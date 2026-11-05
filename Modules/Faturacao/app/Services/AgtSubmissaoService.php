<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

        $this->marcarSemResposta($empresaId);

        $pedidos = AgtSubmissao::query()
            ->whereIn('estado', [AgtSubmissao::ENVIADO, AgtSubmissao::AGUARDA_INTERVENCAO])
            ->whereNotNull('request_id')
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
            ->where(fn ($q) => $q->whereNull('ultima_consulta_em')->orWhere('ultima_consulta_em', '<=', now()->subSeconds($intervalo)))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AgtSubmissao $s) => $s->empresa_id.'|'.$s->request_id);

        foreach ($pedidos as $grupo) {
            // Uma falha de rede num pedido não pode impedir a consulta dos restantes.
            try {
                $parcial = $this->consultarPedido($grupo->all());
            } catch (AgtException $e) {
                if ($e->getCode() !== AgtException::TRANSPORTE) {
                    throw $e;
                }

                $parcial = $this->aguardar($grupo->all(), ['validos' => 0, 'invalidos' => 0, 'erros' => 0, 'pendentes' => 0]);
            }

            foreach ($parcial as $chave => $valor) {
                $total[$chave] += $valor;
            }
        }

        return $total;
    }

    /**
     * Documentos "enviados" sem estado final há demasiado tempo não ficam
     * eternamente a aguardar: passam a exigir intervenção.
     */
    public function marcarSemResposta(?int $empresaId = null): int
    {
        $horas = (int) config('faturacao.agt.sem_resposta_horas', 24);

        $submissoes = AgtSubmissao::query()
            ->where('estado', AgtSubmissao::ENVIADO)
            ->where('enviado_em', '<=', now()->subHours($horas))
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
            ->get();

        foreach ($submissoes as $submissao) {
            $submissao->update([
                'estado' => AgtSubmissao::AGUARDA_INTERVENCAO,
                'erros' => [['codigo' => 'SEM_RESPOSTA', 'descricao' => "A AGT não devolveu um estado final em {$horas} horas."]],
            ]);
        }

        return $submissoes->count();
    }

    /**
     * Reenvio controlado. Só para falhas técnicas: um documento inválido ou
     * rejeitado pela AGT nunca é reenviado — exige um documento de correção.
     */
    public function reenviar(AgtSubmissao $submissao): AgtSubmissao
    {
        if ($submissao->estado !== AgtSubmissao::ERRO || $submissao->request_id !== null) {
            throw new \DomainException('Só se pode reenviar uma submissão em erro técnico sem requestID. Se a AGT já devolveu um requestID, consulta o estado original; nunca cries uma segunda submissão para o mesmo documento.');
        }

        Log::warning('Submissão AGT reenviada manualmente', [
            'submissao_id' => $submissao->id,
            'documento_no' => $submissao->documento_no,
            'estado_anterior' => $submissao->estado,
            'erros_anteriores' => $submissao->erros,
        ]);

        $codigoErro = $submissao->erros[0]['codigo'] ?? null;
        $dados = ['estado' => AgtSubmissao::PENDENTE, 'tentativas' => 0, 'erros' => null, 'request_id' => null];

        // Falha de transporte: a AGT pode ter aceite o pedido apesar do timeout.
        // Mantém UUID e timestamp para repetir exactamente a mesma submissão.
        // Erro local/estrutural: o pedido foi recusado, pelo que uma nova tentativa
        // corrigida deve usar um UUID novo.
        if ($codigoErro !== 'REDE') {
            $dados['submission_uuid'] = null;
            $dados['submission_timestamp'] = null;
            $dados['submission_payload_hash'] = null;
        }

        $submissao->update($dados);

        return $submissao->fresh();
    }

    /** @return array{enviados: int, rejeitados: int, erros: int} */
    protected function enviarEmpresa(int $empresaId): array
    {
        $res = ['enviados' => 0, 'rejeitados' => 0, 'erros' => 0];
        $empresa = Empresa::query()->find($empresaId);

        if (! $empresa) {
            return $res;
        }

        // Uma tentativa com timeout conserva o UUID e o timestamp. Recolhe
        // primeiro todo o lote desse UUID, sem misturar documentos novos.
        $comUuid = AgtSubmissao::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', AgtSubmissao::PENDENTE)
            ->whereNotNull('submission_uuid')
            ->orderBy('id')
            ->first();

        $consultaLote = AgtSubmissao::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', AgtSubmissao::PENDENTE);

        if ($comUuid) {
            $consultaLote->where('submission_uuid', $comUuid->submission_uuid);
        } else {
            $consultaLote->whereNull('submission_uuid');
        }

        $lote = $consultaLote
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

        $uuid = (string) ($enviar[0]->submission_uuid ?: Str::uuid());
        $instanteSubmissao = $enviar[0]->submission_timestamp ?? now();

        foreach ($enviar as $submissao) {
            if ($submissao->submission_uuid !== null && $submissao->submission_uuid !== $uuid) {
                throw AgtException::configuracaoInvalida('o lote contém UUIDs de submissão diferentes; a operação foi interrompida para proteger a idempotência.');
            }

            $submissao->update([
                'submission_uuid' => $uuid,
                'submission_timestamp' => $instanteSubmissao,
            ]);
        }

        $corpo = Jws::canonico([
            'schemaVersion' => '2.0',
            'submissionUUID' => $uuid,
            'taxRegistrationNumber' => (string) $empresa->nif,
            'submissionTimeStamp' => $instanteSubmissao->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => $this->assinaturas->softwareInfo(),
            'numberOfEntries' => count($documentos),
            'documents' => $documentos,
        ]);

        $hashPayload = hash('sha256', $corpo);
        $hashesExistentes = collect($enviar)
            ->pluck('submission_payload_hash')
            ->filter()
            ->unique()
            ->values();

        if ($hashesExistentes->isNotEmpty()
            && ($hashesExistentes->count() !== 1 || $hashesExistentes->first() !== $hashPayload)) {
            // O UUID já foi usado com outro corpo (por exemplo, código alterado
            // entre tentativas). Nunca reutilizar o UUID com conteúdo diferente.
            foreach ($enviar as $submissao) {
                $submissao->update([
                    'estado' => AgtSubmissao::AGUARDA_INTERVENCAO,
                    'erros' => [['codigo' => 'PAYLOAD_DIVERGENTE', 'descricao' => 'O conteúdo reconstruído difere do pedido original associado ao UUID. Não foi reenviado para evitar duplicação fiscal.']],
                ]);
                $res['erros']++;
            }

            return $res;
        }

        foreach ($enviar as $submissao) {
            $submissao->update(['submission_payload_hash' => $hashPayload]);
        }

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
                // Erro do pedido como um todo (estrutura): exige intervenção.
                $submissao->update(['estado' => AgtSubmissao::ERRO, 'erros' => $geral, 'tentativas' => $submissao->tentativas + 1]);
                $res['erros']++;
            } else {
                // A AGT recusou o pedido/lote e identificou outros documentos;
                // os restantes seguem num pedido novo, com UUID novo.
                $submissao->update(['submission_uuid' => null, 'submission_timestamp' => null, 'submission_payload_hash' => null]);
            }
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
