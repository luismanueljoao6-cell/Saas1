<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Support\Jws;

/**
 * Envia à AGT (registarFactura) os documentos emitidos e acompanha a
 * validação diferida (obterEstado). O envio é por empresa e em lotes de, no
 * máximo, `agt.lote_maximo` documentos.
 */
class AgtSubmissaoService
{
    public function __construct(
        protected AgtClient $cliente,
        protected AgtAssinaturaService $assinaturas,
        protected AgtDocumentoBuilder $builder,
    ) {}

    /** @return array{enviados: int, rejeitados: int, erros: int} */
    public function enviarPendentes(?int $empresaId = null): array
    {
        $total = ['enviados' => 0, 'rejeitados' => 0, 'erros' => 0];

        $empresas = AgtSubmissao::query()
            ->where('estado', AgtSubmissao::PENDENTE)
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
            ->distinct()
            ->pluck('empresa_id');

        foreach ($empresas as $id) {
            $parcial = Cache::lock("agt-enviar-{$id}", 120)->get(fn () => $this->enviarEmpresa((int) $id));

            foreach (is_array($parcial) ? $parcial : [] as $chave => $valor) {
                $total[$chave] += $valor;
            }
        }

        return $total;
    }

    /** @return array{validos: int, invalidos: int, erros: int, pendentes: int} */
    public function consultarEstados(?int $empresaId = null): array
    {
        $total = ['validos' => 0, 'invalidos' => 0, 'erros' => 0, 'pendentes' => 0];
        $intervalo = (int) config('faturacao.agt.consulta_intervalo', 60);

        $pedidos = AgtSubmissao::query()
            ->where('estado', AgtSubmissao::ENVIADO)
            ->whereNotNull('request_id')
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
            ->where(fn ($q) => $q->whereNull('ultima_consulta_em')->orWhere('ultima_consulta_em', '<=', now()->subSeconds($intervalo)))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AgtSubmissao $s) => $s->empresa_id.'|'.$s->request_id);

        foreach ($pedidos as $grupo) {
            foreach ($this->consultarPedido($grupo->all()) as $chave => $valor) {
                $total[$chave] += $valor;
            }
        }

        return $total;
    }

    /** @return array{enviados: int, rejeitados: int, erros: int} */
    protected function enviarEmpresa(int $empresaId): array
    {
        $res = ['enviados' => 0, 'rejeitados' => 0, 'erros' => 0];
        $empresa = Empresa::query()->find($empresaId);

        if (! $empresa) {
            return $res;
        }

        $lote = AgtSubmissao::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', AgtSubmissao::PENDENTE)
            ->orderBy('id')
            ->limit((int) config('faturacao.agt.lote_maximo', 30))
            ->get();

        $enviar = [];
        $documentos = [];

        foreach ($lote as $submissao) {
            try {
                $documentos[] = $this->builder->construir($this->carregarDocumento($submissao));
                $enviar[] = $submissao;
            } catch (AgtException $e) {
                // Documento que não consegue ser enviado (ex.: isento): não bloqueia o resto.
                $submissao->update(['estado' => AgtSubmissao::ERRO, 'erros' => [['codigo' => 'LOCAL', 'descricao' => $e->getMessage()]]]);
                $res['erros']++;
            }
        }

        if ($enviar === []) {
            return $res;
        }

        $uuid = (string) Str::uuid();
        $corpo = Jws::canonico([
            'schemaVersion' => '2.0',
            'submissionUUID' => $uuid,
            'taxRegistrationNumber' => (string) $empresa->nif,
            'submissionTimeStamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => $this->assinaturas->softwareInfo(),
            'numberOfEntries' => count($documentos),
            'documents' => $documentos,
        ]);

        try {
            $resposta = $this->cliente->enviar('registar_factura', $corpo);
        } catch (AgtException $e) {
            if ($e->getCode() !== AgtException::TRANSPORTE) {
                throw $e; // credenciais/configuração: não gasta tentativas
            }

            return $this->falhaTransitoria($enviar, $e->getMessage(), $res);
        }

        $http = $resposta['http'];
        $requestId = (string) ($resposta['corpo']['requestID'] ?? '');
        $erros = AgtClient::normalizarErros($resposta['corpo']['errorList'] ?? []);

        if ($http === 401 || $http === 403) {
            throw AgtException::configuracaoInvalida("a AGT recusou as credenciais (HTTP {$http}).");
        }

        // Aceite — ou pedido duplicado (E09), caso em que a AGT devolve o requestID original.
        if ($requestId !== '') {
            foreach ($enviar as $submissao) {
                $submissao->update([
                    'estado' => AgtSubmissao::ENVIADO,
                    'submission_uuid' => $uuid,
                    'request_id' => $requestId,
                    'tentativas' => $submissao->tentativas + 1,
                    'enviado_em' => now(),
                    'erros' => null,
                ]);
                $res['enviados']++;
            }

            return $res;
        }

        if ($erros !== []) {
            return $this->rejeitarPorDocumento($enviar, $erros, $res);
        }

        return $this->falhaTransitoria($enviar, "resposta inesperada da AGT (HTTP {$http})", $res);
    }

    /**
     * @param  array<int, AgtSubmissao>  $lote
     * @param  array<int, array<string, string>>  $erros
     * @param  array{enviados: int, rejeitados: int, erros: int}  $res
     * @return array{enviados: int, rejeitados: int, erros: int}
     */
    protected function rejeitarPorDocumento(array $lote, array $erros, array $res): array
    {
        $porDocumento = [];
        $geral = [];

        foreach ($erros as $erro) {
            if (isset($erro['documento'])) {
                $porDocumento[$erro['documento']][] = $erro;
            } else {
                $geral[] = $erro;
            }
        }

        foreach ($lote as $submissao) {
            if (isset($porDocumento[$submissao->documento_no])) {
                $submissao->update(['estado' => AgtSubmissao::REJEITADO, 'erros' => $porDocumento[$submissao->documento_no], 'tentativas' => $submissao->tentativas + 1]);
                $res['rejeitados']++;
            } elseif ($porDocumento === []) {
                // Erro do pedido como um todo (estrutura): exige intervenção, não se repete sozinho.
                $submissao->update(['estado' => AgtSubmissao::ERRO, 'erros' => $geral, 'tentativas' => $submissao->tentativas + 1]);
                $res['erros']++;
            }
            // Os restantes documentos do lote ficam pendentes e seguem no próximo envio.
        }

        return $res;
    }

    /**
     * @param  array<int, AgtSubmissao>  $lote
     * @param  array{enviados: int, rejeitados: int, erros: int}  $res
     * @return array{enviados: int, rejeitados: int, erros: int}
     */
    protected function falhaTransitoria(array $lote, string $motivo, array $res): array
    {
        $maximo = (int) config('faturacao.agt.max_tentativas', 5);

        foreach ($lote as $submissao) {
            $tentativas = $submissao->tentativas + 1;
            $dados = ['tentativas' => $tentativas];

            if ($tentativas >= $maximo) {
                $dados += ['estado' => AgtSubmissao::ERRO, 'erros' => [['codigo' => 'REDE', 'descricao' => $motivo]]];
                $res['erros']++;
            }

            $submissao->update($dados);
        }

        return $res;
    }

    /**
     * @param  array<int, AgtSubmissao>  $grupo  submissões do mesmo pedido (requestID)
     * @return array{validos: int, invalidos: int, erros: int, pendentes: int}
     */
    protected function consultarPedido(array $grupo): array
    {
        $res = ['validos' => 0, 'invalidos' => 0, 'erros' => 0, 'pendentes' => 0];
        $empresa = Empresa::query()->find($grupo[0]->empresa_id);
        $requestId = (string) $grupo[0]->request_id;

        if (! $empresa) {
            return $res;
        }

        $nif = (string) $empresa->nif;
        $corpo = Jws::canonico([
            'schemaVersion' => '2.0',
            'submissionUUID' => (string) Str::uuid(),
            'taxRegistrationNumber' => $nif,
            'submissionTimeStamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => $this->assinaturas->softwareInfo(),
            'jwsSignature' => $this->assinaturas->assinarRequisicao((int) $empresa->id, ['taxRegistrationNumber' => $nif, 'requestID' => $requestId]),
            'requestID' => $requestId,
        ]);

        $resposta = $this->cliente->enviar('obter_estado', $corpo);
        $http = $resposta['http'];
        $dados = $resposta['corpo'];

        if ($http === 400) {
            return $this->marcarGrupo($grupo, AgtSubmissao::ERRO, AgtClient::normalizarErros($dados['errorList'] ?? $dados['requestErrorList'] ?? [['idError' => 'E96', 'descriptionError' => 'solicitação mal efectuada']]), $res, 'erros');
        }

        if ($http === 422 || $http === 429) {
            $codigo = (string) ($dados['errorCode'] ?? $dados['idError'] ?? ($dados['errorList'][0]['idError'] ?? ''));

            // E95: NIF do emissor diferente do pedido original — não se resolve a esperar.
            if ($codigo === 'E95') {
                return $this->marcarGrupo($grupo, AgtSubmissao::ERRO, [['codigo' => 'E95', 'descricao' => 'NIF do emissor diferente do do pedido original']], $res, 'erros');
            }

            return $this->aguardar($grupo, $res);
        }

        if ($http !== 200) {
            return $this->aguardar($grupo, $res);
        }

        $codigoResultado = (int) ($dados['resultCode'] ?? -1);

        if ($codigoResultado === 9) {
            return $this->marcarGrupo($grupo, AgtSubmissao::ERRO, [['codigo' => 'E99', 'descricao' => 'Processamento cancelado pela AGT']], $res, 'erros');
        }

        if (! in_array($codigoResultado, [0, 1, 2], true)) {
            return $this->aguardar($grupo, $res); // 7 (prematuro), 8 (em curso) ou desconhecido
        }

        $estados = [];
        foreach ((array) ($dados['documentStatusList'] ?? []) as $item) {
            if (is_array($item) && isset($item['documentNo'])) {
                $estados[(string) $item['documentNo']] = $item;
            }
        }

        foreach ($grupo as $submissao) {
            $item = $estados[$submissao->documento_no] ?? null;

            if ($item === null) {
                $submissao->update(['ultima_consulta_em' => now()]);
                $res['pendentes']++;
            } elseif (($item['documentStatus'] ?? '') === 'V') {
                $submissao->update(['estado' => AgtSubmissao::VALIDO, 'validado_em' => now(), 'ultima_consulta_em' => now(), 'erros' => null]);
                $res['validos']++;
            } else {
                $submissao->update([
                    'estado' => AgtSubmissao::INVALIDO,
                    'ultima_consulta_em' => now(),
                    'erros' => AgtClient::normalizarErros($item['errorList'] ?? []),
                ]);
                $res['invalidos']++;
            }
        }

        return $res;
    }

    /**
     * @param  array<int, AgtSubmissao>  $grupo
     * @param  array{validos: int, invalidos: int, erros: int, pendentes: int}  $res
     * @return array{validos: int, invalidos: int, erros: int, pendentes: int}
     */
    protected function aguardar(array $grupo, array $res): array
    {
        foreach ($grupo as $submissao) {
            $submissao->update(['ultima_consulta_em' => now()]);
            $res['pendentes']++;
        }

        return $res;
    }

    /**
     * @param  array<int, AgtSubmissao>  $grupo
     * @param  array<int, array<string, string>>  $erros
     * @param  array{validos: int, invalidos: int, erros: int, pendentes: int}  $res
     * @return array{validos: int, invalidos: int, erros: int, pendentes: int}
     */
    protected function marcarGrupo(array $grupo, string $estado, array $erros, array $res, string $chave): array
    {
        foreach ($grupo as $submissao) {
            $submissao->update(['estado' => $estado, 'erros' => $erros, 'ultima_consulta_em' => now()]);
            $res[$chave]++;
        }

        return $res;
    }

    protected function carregarDocumento(AgtSubmissao $submissao): Fatura|NotaCreditoDebito|Recibo
    {
        $classe = $submissao->documento_type;

        if (! in_array($classe, [Fatura::class, NotaCreditoDebito::class, Recibo::class], true)) {
            throw AgtException::configuracaoInvalida("tipo de documento desconhecido '{$classe}'.");
        }

        return $classe::withoutGlobalScopes()
            ->where('empresa_id', $submissao->empresa_id)
            ->findOrFail($submissao->documento_id);
    }
}
