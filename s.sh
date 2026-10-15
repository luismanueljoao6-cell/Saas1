(
# Fase 2b — Facturação Electrónica AGT: envio (registarFactura) e consulta de estado (obterEstado).
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

[ -d Modules/Faturacao ] || { echo "ERRO: corre isto na raiz do projeto Saas1."; exit 1; }
git checkout main
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "ERRO: tens alterações por commitar. Faz commit ou 'git stash' primeiro."; exit 1
fi
git pull --ff-only origin main
[ -f Modules/Faturacao/app/Services/AgtClient.php ] || { echo "ERRO: a Fase 2a (séries AGT) ainda não está na main. Corre primeiro o comando da Fase 2a."; exit 1; }
trap 'echo; echo "FALHOU. Nada foi commitado. Para desfazer: git restore --staged --worktree . && git clean -fd -- Modules/Faturacao"' ERR
mkdir -p Modules/Faturacao/database/migrations
cat > Modules/Faturacao/database/migrations/2026_10_14_000013_create_agt_submissoes_table.php <<'EOF_F2B_0'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado do envio de cada documento à AGT. Vive numa tabela própria porque
     * os documentos emitidos são imutáveis (triggers) e não podem guardar o
     * progresso do registo/validação. Fase 2b da adequação à AGT.
     */
    public function up(): void
    {
        Schema::create('agt_submissoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('documento_type');
            $table->unsignedBigInteger('documento_id');
            $table->string('documento_no', 60);

            // pendente | enviado | valido | invalido | rejeitado | erro
            $table->string('estado', 20)->default('pendente');
            $table->string('submission_uuid', 36)->nullable();
            $table->string('request_id', 15)->nullable();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->json('erros')->nullable();

            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('ultima_consulta_em')->nullable();
            $table->timestamp('validado_em')->nullable();
            $table->timestamps();

            $table->unique(['documento_type', 'documento_id']);
            $table->index(['empresa_id', 'estado']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agt_submissoes');
    }
};
EOF_F2B_0
mkdir -p Modules/Faturacao/app/Models
cat > Modules/Faturacao/app/Models/AgtSubmissao.php <<'EOF_F2B_1'
<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Estado do envio de um documento fiscal à AGT. Sem BelongsToTenant de
 * propósito: é lido por comandos/jobs que operam entre empresas e nunca é
 * exposto em pedidos HTTP de utilizadores.
 */
class AgtSubmissao extends Model
{
    public const PENDENTE = 'pendente';

    public const ENVIADO = 'enviado';

    public const VALIDO = 'valido';

    public const INVALIDO = 'invalido';

    public const REJEITADO = 'rejeitado';

    public const ERRO = 'erro';

    protected $table = 'agt_submissoes';

    protected $fillable = [
        'empresa_id',
        'documento_type',
        'documento_id',
        'documento_no',
        'estado',
        'submission_uuid',
        'request_id',
        'tentativas',
        'erros',
        'enviado_em',
        'ultima_consulta_em',
        'validado_em',
    ];

    protected function casts(): array
    {
        return [
            'tentativas' => 'integer',
            'erros' => 'array',
            'enviado_em' => 'datetime',
            'ultima_consulta_em' => 'datetime',
            'validado_em' => 'datetime',
        ];
    }
}
EOF_F2B_1
mkdir -p Modules/Faturacao/app/Services
cat > Modules/Faturacao/app/Services/AgtDocumentoBuilder.php <<'EOF_F2B_2'
<?php

namespace Modules\Faturacao\Services;

use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\Jws;
use Modules\Faturacao\Support\NumeroJson;

/**
 * Monta o objecto "document" do serviço registarFactura da AGT a partir de um
 * documento emitido. Os campos que entram na assinatura (número, datas,
 * cliente, totais) são copiados do próprio JWS guardado no documento — assim
 * o que se envia é, por construção, igual ao que foi assinado, mesmo que o
 * cliente tenha sido editado depois.
 */
class AgtDocumentoBuilder
{
    /** @return array<string, mixed> */
    public function construir(Fatura|NotaCreditoDebito|Recibo $documento): array
    {
        $assinatura = (string) $documento->hash;
        $assinado = json_decode((string) Jws::payload($assinatura), true);

        if (! is_array($assinado) || ! isset($assinado['documentTotals'])) {
            throw AgtException::configuracaoInvalida("o documento {$documento->numero_documento} não tem uma assinatura JWS válida.");
        }

        if (($documento->moeda ?? 'AOA') !== 'AOA') {
            throw AgtException::configuracaoInvalida("o documento {$documento->numero_documento} não está em AOA; facturas em divisa ainda não são suportadas.");
        }

        $resultado = [
            'documentNo' => $assinado['documentNo'],
            'documentStatus' => 'N',
            'jwsDocumentSignature' => $assinatura,
            'documentDate' => $assinado['documentDate'],
            'documentType' => $assinado['documentType'],
            'systemEntryDate' => $documento->data_hora_sistema->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'customerTaxID' => $assinado['customerTaxID'],
            'customerCountry' => $assinado['customerCountry'],
            'companyName' => $assinado['companyName'],
        ];

        if ($documento instanceof Recibo) {
            $resultado['paymentReceipt'] = $this->recibo($documento, $assinado['documentTotals']);
        } else {
            $resultado['lines'] = $this->linhas($documento);
        }

        $resultado['documentTotals'] = [
            'taxPayable' => $this->numero($assinado['documentTotals']['taxPayable']),
            'netTotal' => $this->numero($assinado['documentTotals']['netTotal']),
            'grossTotal' => $this->numero($assinado['documentTotals']['grossTotal']),
        ];

        return $resultado;
    }

    /** @return array<int, array<string, mixed>> */
    protected function linhas(Fatura|NotaCreditoDebito $documento): array
    {
        $ehNotaCredito = $documento instanceof NotaCreditoDebito && $documento->tipo === 'credito';
        $referencia = null;

        if ($documento instanceof NotaCreditoDebito) {
            $original = $documento->fatura_id ? $this->fatura($documento->empresa_id, $documento->fatura_id) : null;

            if ($ehNotaCredito && ! $original) {
                throw AgtException::configuracaoInvalida("a nota de crédito {$documento->numero_documento} não refere a fatura original (regra E13 da AGT).");
            }

            if ($original) {
                $referencia = ['reference' => (string) $original->numero_documento, 'reason' => mb_substr((string) $documento->motivo, 0, 60)];
            }
        }

        $linhas = [];

        foreach ($documento->linhas()->get() as $indice => $linha) {
            $produto = ($linha->produto_id ?? null) ? Produto::withoutGlobalScopes()->find($linha->produto_id) : null;
            $valor = NumeroJson::deCentimos(Dinheiro::centimos($linha->valor_sem_iva));

            $item = [
                'lineNumber' => $indice + 1,
                'operationType' => (string) config('faturacao.agt.operation_type_padrao', 'SG'),
                'productCode' => mb_substr((string) ($produto?->codigo ?: (($linha->produto_id ?? null) ? 'P'.$linha->produto_id : 'SERVICO')), 0, 60),
                'productDescription' => mb_substr((string) $linha->descricao, 0, 200),
                'quantity' => NumeroJson::deDecimal((string) $linha->quantidade),
                'unitOfMeasure' => mb_substr(strtoupper((string) ($produto?->unidade ?: config('faturacao.agt.unidade_padrao', 'UN'))), 0, 20),
                'unitPriceBase' => NumeroJson::deDecimal((string) $linha->preco_unitario),
                'unitPrice' => NumeroJson::deDecimal((string) $linha->preco_unitario),
            ];

            if ($referencia !== null) {
                $item['referenceInfo'] = $referencia;
            }

            // Nota de crédito a débito; os restantes documentos a crédito (regras E16/E17).
            $item[$ehNotaCredito ? 'debitAmount' : 'creditAmount'] = $valor;
            $item['taxes'] = [[
                'taxType' => 'IVA',
                'taxCountryRegion' => 'AO',
                'taxCode' => $this->codigoImposto((string) $linha->taxa_iva),
                'taxPercentage' => NumeroJson::deDecimal((string) $linha->taxa_iva),
                'taxContribution' => NumeroJson::deCentimos(Dinheiro::centimos($linha->valor_iva)),
            ]];
            $item['settlementAmount'] = NumeroJson::deCentimos(0);

            $linhas[] = $item;
        }

        return $linhas;
    }

    /**
     * @param  array<string, mixed>  $totais  documentTotals assinado
     * @return array<string, mixed>
     */
    protected function recibo(Recibo $documento, array $totais): array
    {
        $fatura = $documento->fatura_id ? $this->fatura($documento->empresa_id, $documento->fatura_id) : null;

        if (! $fatura) {
            throw AgtException::configuracaoInvalida("o recibo {$documento->numero_documento} não refere uma fatura; recibos de adiantamento ainda não são suportados.");
        }

        return [
            'sourceDocuments' => [[
                'lineNo' => 1,
                'sourceDocumentID' => [
                    'OriginatingON' => (string) $fatura->numero_documento,
                    'documentDate' => $fatura->data_emissao->format('Y-m-d'),
                ],
                'creditAmount' => $this->numero($totais['netTotal']),
            ]],
        ];
    }

    protected function fatura(int $empresaId, int $faturaId): ?Fatura
    {
        return Fatura::withoutGlobalScopes()->where('empresa_id', $empresaId)->find($faturaId);
    }

    protected function codigoImposto(string $taxa): string
    {
        if ((float) $taxa == 0.0) {
            throw AgtException::configuracaoInvalida('linhas isentas (IVA 0%) ainda não são suportadas na comunicação com a AGT: falta o código de isenção (Anexo 6.4).');
        }

        $mapa = (array) config('faturacao.agt.codigos_taxa', []);
        $chave = number_format((float) $taxa, 2, '.', '');

        return $mapa[$chave] ?? throw AgtException::configuracaoInvalida("a taxa de IVA {$chave}% não tem código AGT (config faturacao.agt.codigos_taxa).");
    }

    protected function numero(mixed $valor): NumeroJson
    {
        return NumeroJson::deCentimos((int) round(((float) $valor) * 100));
    }
}
EOF_F2B_2
mkdir -p Modules/Faturacao/app/Services
cat > Modules/Faturacao/app/Services/AgtSubmissaoService.php <<'EOF_F2B_3'
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
EOF_F2B_3
mkdir -p Modules/Faturacao/app/Console
cat > Modules/Faturacao/app/Console/SincronizarAgtCommand.php <<'EOF_F2B_4'
<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Services\AgtSubmissaoService;

/**
 * Envia à AGT os documentos pendentes e consulta o estado dos já enviados.
 * Pensado para correr de minuto a minuto (agendado em routes/console.php).
 */
class SincronizarAgtCommand extends Command
{
    protected $signature = 'faturacao:agt-sincronizar {--empresa= : Limita a uma empresa (ID)}';

    protected $description = 'Envia à AGT os documentos pendentes (registarFactura) e consulta o estado dos enviados (obterEstado)';

    public function handle(AgtSubmissaoService $servico): int
    {
        $empresa = $this->option('empresa') !== null ? (int) $this->option('empresa') : null;

        try {
            $envio = $servico->enviarPendentes($empresa);
            $estado = $servico->consultarEstados($empresa);
        } catch (AgtException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Envio: {$envio['enviados']} enviados, {$envio['rejeitados']} rejeitados, {$envio['erros']} com erro.");
        $this->info("Estado: {$estado['validos']} válidos, {$estado['invalidos']} inválidos, {$estado['erros']} com erro, {$estado['pendentes']} ainda em processamento.");

        return self::SUCCESS;
    }
}
EOF_F2B_4
mkdir -p Modules/Faturacao/tests/Feature
cat > Modules/Faturacao/tests/Feature/AgtSubmissaoTest.php <<'EOF_F2B_5'
<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\AgtSubmissaoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Jws;
use Modules\Faturacao\Support\NumeroJson;
use Tests\TestCase;

class AgtSubmissaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    /** @var array<string, mixed> */
    protected array $respostaRegistar = ['requestID' => '202500000010689', 'errorList' => []];

    /** @var array<string, mixed> */
    protected array $respostaEstado = ['resultCode' => '8'];

    protected bool $falharRede = false;

    protected function setUp(): void
    {
        parent::setUp();

        [$privadaEmpresa] = $this->gerarPar();
        [$privadaSoftware] = $this->gerarPar();

        config([
            'faturacao.chave_privada_path' => $privadaEmpresa,
            'faturacao.chaves_dir' => sys_get_temp_dir().'/agt-sem-chaves-'.uniqid(),
            'faturacao.agt.ativo' => true,
            'faturacao.agt.ambiente' => 'homologacao',
            'faturacao.agt.utilizador' => 'produtor',
            'faturacao.agt.palavra_passe' => 's3nh@',
            'faturacao.agt.software.chave_privada_path' => $privadaSoftware,
            'faturacao.agt.software.signature_version' => null,
            'faturacao.saft_numero_certificado' => 'C_134',
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        $this->fingirAgt();
    }

    /** @return array{0: string} */
    protected function gerarPar(): array
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/agt-priv-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);
        $this->beforeApplicationDestroyed(fn () => @unlink($privada));

        return [$privada];
    }

    /**
     * Simula a AGT uma só vez (um novo Http::fake() apagaria os pedidos
     * gravados e as respostas antigas ganhariam às novas); as respostas lidas
     * aqui vêm das propriedades, que cada teste pode alterar.
     */
    protected function fingirAgt(): void
    {
        Http::fake([
            '*solicitarSerie' => fn (Request $r) => Http::response([
                'resultCode' => 1,
                'errorList' => [''],
                'seriesFEResult' => [
                    'seriesCode' => $r['documentType'].'6325S2C',
                    'authorizedQuantity' => '1000',
                    'firstDocumentNo' => '1',
                    'lastDocumentNo' => '1000',
                ],
            ], 200),
            '*registarFactura' => fn () => $this->falharRede
                ? throw new ConnectionException('timeout')
                : Http::response($this->respostaRegistar, isset($this->respostaRegistar['requestID']) ? 200 : 400),
            '*obterEstado' => fn () => Http::response($this->respostaEstado, 200),
        ]);
    }

    protected function emitirFatura(): Fatura
    {
        foreach (['FT', 'RC'] as $tipo) {
            app(AgtSerieService::class)->solicitar($this->empresa, $tipo, now()->year);
        }

        $servico = app(FaturaService::class);
        $linhas = [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]];

        return $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, $linhas));
    }

    protected function pedidoPara(string $servico): Request
    {
        return collect(Http::recorded())->map(fn ($par) => $par[0])->first(fn (Request $r) => str_contains($r->url(), $servico));
    }

    public function test_emitir_com_agt_ativa_cria_submissao_pendente(): void
    {
        $fatura = $this->emitirFatura();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('pendente', $submissao->estado);
        $this->assertSame('FT FT6325S2C/1', $submissao->documento_no);
        $this->assertSame($fatura->id, $submissao->documento_id);
    }

    public function test_enviar_pendentes_monta_o_documento_segundo_a_especificacao(): void
    {
        $fatura = $this->emitirFatura();

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(1, $res['enviados']);
        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('enviado', $submissao->estado);
        $this->assertSame('202500000010689', $submissao->request_id);

        $dados = $this->pedidoPara('registarFactura')->data();
        $this->assertSame('2.0', $dados['schemaVersion']);
        $this->assertSame('5001636863', $dados['taxRegistrationNumber']);
        $this->assertSame(1, $dados['numberOfEntries']);
        $this->assertSame('C_134', $dados['softwareInfo']['softwareInfoDetail']['softwareValidationNumber']);

        $documento = $dados['documents'][0];
        $this->assertSame('FT FT6325S2C/1', $documento['documentNo']);
        $this->assertSame('N', $documento['documentStatus']);
        $this->assertSame($fatura->hash, $documento['jwsDocumentSignature']);
        $this->assertSame('FT', $documento['documentType']);
        $this->assertSame('999999999', $documento['customerTaxID']);
        $this->assertSame('AO', $documento['customerCountry']);
        $this->assertSame(['taxPayable' => 140, 'netTotal' => 1000, 'grossTotal' => 1140], $documento['documentTotals']);

        $linha = $documento['lines'][0];
        $this->assertSame(1, $linha['lineNumber']);
        $this->assertSame('SG', $linha['operationType']);
        $this->assertSame(1, $linha['quantity']);
        $this->assertSame(1000, $linha['unitPriceBase']);
        $this->assertSame(1000, $linha['creditAmount']);
        $this->assertArrayNotHasKey('debitAmount', $linha);
        $this->assertSame(0, $linha['settlementAmount']);
        $this->assertSame(
            ['taxType' => 'IVA', 'taxCountryRegion' => 'AO', 'taxCode' => 'NOR', 'taxPercentage' => 14, 'taxContribution' => 140],
            $linha['taxes'][0],
        );

        // O que se envia é exactamente o objecto que foi assinado.
        $assinado = json_decode(Jws::payload($documento['jwsDocumentSignature']), true);
        $this->assertSame($assinado['documentTotals'], $documento['documentTotals']);
        $this->assertSame($assinado['documentNo'], $documento['documentNo']);
    }

    public function test_recibo_vai_com_payment_receipt_e_sem_linhas(): void
    {
        $fatura = $this->emitirFatura();
        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '500.00', 'dinheiro', $fatura->id);

        $this->assertSame(2, app(AgtSubmissaoService::class)->enviarPendentes()['enviados']);

        $documentos = $this->pedidoPara('registarFactura')->data()['documents'];
        $recibo = $documentos[1];

        $this->assertSame('RC', $recibo['documentType']);
        $this->assertArrayNotHasKey('lines', $recibo);
        $origem = $recibo['paymentReceipt']['sourceDocuments'][0];
        $this->assertSame(1, $origem['lineNo']);
        $this->assertSame('FT FT6325S2C/1', $origem['sourceDocumentID']['OriginatingON']);
        $this->assertEqualsWithDelta(438.59, $origem['creditAmount'], 0.0001);
    }

    public function test_estado_valido_e_invalido_sao_registados(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $this->respostaEstado = [
            'resultCode' => '0',
            'documentStatusList' => [['documentNo' => 'FT FT6325S2C/1', 'documentStatus' => 'V']],
        ];

        $res = app(AgtSubmissaoService::class)->consultarEstados();

        $this->assertSame(1, $res['validos']);
        $this->assertSame('valido', AgtSubmissao::query()->firstOrFail()->estado);
        $this->assertNotNull(AgtSubmissao::query()->firstOrFail()->validado_em);
    }

    public function test_documento_invalido_guarda_os_erros_da_agt(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $this->respostaEstado = [
            'resultCode' => '2',
            'documentStatusList' => [[
                'documentNo' => 'FT FT6325S2C/1',
                'documentStatus' => 'I',
                'errorList' => [['errorCode' => 'E22', 'errorDescription' => 'Total de impostos não coincide']],
            ]],
        ];

        app(AgtSubmissaoService::class)->consultarEstados();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('invalido', $submissao->estado);
        $this->assertSame('E22', $submissao->erros[0]['codigo']);
    }

    public function test_processamento_em_curso_nao_e_consultado_de_novo_antes_do_intervalo(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);
        $servico->enviarPendentes();

        $servico->consultarEstados();
        $servico->consultarEstados();

        $consultas = collect(Http::recorded())->filter(fn ($par) => str_contains($par[0]->url(), 'obterEstado'));
        $this->assertCount(1, $consultas);
        $this->assertSame('enviado', AgtSubmissao::query()->firstOrFail()->estado);
    }

    public function test_pedido_de_estado_leva_jws_signature_do_emissor(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);
        $servico->enviarPendentes();
        $servico->consultarEstados();

        $dados = $this->pedidoPara('obterEstado')->data();

        $this->assertSame('202500000010689', $dados['requestID']);
        $this->assertSame(
            '{"taxRegistrationNumber":"5001636863","requestID":"202500000010689"}',
            Jws::payload($dados['jwsSignature']),
        );
    }

    public function test_rejeicao_por_documento_marca_so_esse_documento(): void
    {
        $this->respostaRegistar = ['errorList' => [['idError' => 'E16', 'descriptionError' => 'Soma a crédito inválida', 'documentNo' => 'FT FT6325S2C/1']]];
        $this->emitirFatura();

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(1, $res['rejeitados']);
        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('rejeitado', $submissao->estado);
        $this->assertSame('E16', $submissao->erros[0]['codigo']);
        $this->assertSame('FT FT6325S2C/1', $submissao->erros[0]['documento']);
    }

    public function test_falha_de_rede_mantem_pendente_e_conta_tentativas(): void
    {
        $this->emitirFatura();

        $this->falharRede = true;
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('pendente', $submissao->estado);
        $this->assertSame(1, $submissao->tentativas);
    }

    public function test_documento_que_nao_pode_ir_para_a_agt_fica_em_erro_sem_bloquear_o_envio(): void
    {
        $this->emitirFatura();

        // Taxa sem código de imposto AGT configurado: o documento não pode ser montado.
        config(['faturacao.agt.codigos_taxa' => []]);

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(1, $res['erros']);
        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('erro', $submissao->estado);
        $this->assertSame('LOCAL', $submissao->erros[0]['codigo']);
        $this->assertStringContainsString('codigos_taxa', $submissao->erros[0]['descricao']);
    }

    public function test_numero_json_de_decimal_remove_zeros_a_direita(): void
    {
        $this->assertSame('250', NumeroJson::deDecimal('250.00')->literal);
        $this->assertSame('0.5', NumeroJson::deDecimal('0.500')->literal);
        $this->assertSame('14', NumeroJson::deDecimal('14.00')->literal);
        $this->assertSame('0', NumeroJson::deDecimal('0.000')->literal);
        $this->assertSame('1.25', NumeroJson::deDecimal('1.250')->literal);
    }
}
EOF_F2B_5
python3 - <<'EOF_PATCH_2B'
import re, sys, pathlib

M = pathlib.Path('Modules/Faturacao')

def ler(p): return p.read_text(encoding='utf-8')
def gravar(p, t): p.write_text(t, encoding='utf-8')

def falhar(msg):
    sys.exit(f'ERRO: {msg} Nada foi commitado; vê as instruções acima para desfazer.')

# ---------- AgtException: código "transporte" ----------
f = M / 'app/Exceptions/AgtException.php'
t = ler(f)
if 'TRANSPORTE' not in t:
    t, n = re.subn(r"public function __construct\(string \$message, public readonly array \$erros = \[\]\)\s*\{\s*parent::__construct\(\$message\);\s*\}",
                   "public function __construct(string $message, public readonly array $erros = [], int $codigo = 0)\n    {\n        parent::__construct($message, $codigo);\n    }", t, count=1)
    if n != 1: falhar('construtor de AgtException não encontrado.')
    t, n = re.subn(r"(class AgtException extends Exception\s*\{\n)", r"\1    public const TRANSPORTE = 1;\n\n", t, count=1)
    if n != 1: falhar('classe AgtException não encontrada.')
    t, n = re.subn(r"return new self\('Falha de comunicação com a AGT: '\.\$motivo\);", "return new self('Falha de comunicação com a AGT: '.$motivo, [], self::TRANSPORTE);", t, count=1)
    if n != 1: falhar('AgtException::transporte não encontrado.')
    gravar(f, t)

# ---------- AgtClient::normalizarErros: número do documento ----------
f = M / 'app/Services/AgtClient.php'
t = ler(f)
if "'documento'" not in t:
    t, n = re.subn(r"\$erros\[\] = \['codigo' => \$codigo, 'descricao' => \$descricao\];",
                   "$erro = ['codigo' => $codigo, 'descricao' => $descricao];\n\n                if (! empty($item['documentNo'])) {\n                    $erro['documento'] = (string) $item['documentNo'];\n                }\n\n                $erros[] = $erro;", t, count=1)
    if n != 1: falhar('normalizarErros não encontrado em AgtClient.')
    gravar(f, t)

# ---------- NumeroJson::deDecimal ----------
f = M / 'app/Support/NumeroJson.php'
t = ler(f)
if 'deDecimal' not in t:
    metodo = """
    /** @param  string  $decimal  decimal em texto (ex.: "250.00", "0.500") */
    public static function deDecimal(string $decimal): self
    {
        $texto = trim($decimal);

        if (preg_match('/^-?\\d+(\\.\\d+)?$/', $texto) !== 1) {
            throw new \\InvalidArgumentException("Número decimal inválido: {$decimal}");
        }

        if (str_contains($texto, '.')) {
            $texto = rtrim(rtrim($texto, '0'), '.');
        }

        return new self($texto === '' || $texto === '-' ? '0' : $texto);
    }
"""
    idx = t.rstrip().rfind('}')
    t = t[:idx].rstrip('\n') + '\n' + metodo + '}\n'
    gravar(f, t)

# ---------- Trait: criar a submissão ao emitir ----------
f = M / 'app/Traits/AssinavelAgt.php'
t = ler(f)
if 'bootAssinavelAgt' not in t:
    t, n = re.subn(r"(use Modules\\Faturacao\\Models\\Cliente;\n)", r"use Modules\\Faturacao\\Models\\AgtSubmissao;\n\1", t, count=1)
    if n != 1: falhar('import de Cliente não encontrado na trait AssinavelAgt.')
    boot = """    /**
     * Com a comunicação à AGT ativa, cada documento emitido fica registado para
     * envio — na mesma transação da emissão, por isso nunca há um sem o outro.
     */
    public static function bootAssinavelAgt(): void
    {
        static::saved(function ($documento): void {
            if (config('faturacao.agt.ativo') && $documento->estaEmitido() && $documento->wasChanged('hash')) {
                AgtSubmissao::query()->firstOrCreate(
                    ['documento_type' => $documento->getMorphClass(), 'documento_id' => $documento->getKey()],
                    ['empresa_id' => $documento->empresa_id, 'documento_no' => (string) $documento->numero_documento, 'estado' => AgtSubmissao::PENDENTE],
                );
            }
        });
    }

"""
    t, n = re.subn(r"(trait AssinavelAgt\s*\{\n)", lambda m: m.group(1) + boot, t, count=1)
    if n != 1: falhar('trait AssinavelAgt não encontrada.')
    gravar(f, t)

# ---------- Config ----------
f = M / 'config/config.php'
t = ler(f)
if "'lote_maximo'" not in t:
    ancora = "        'estabelecimento_padrao' => env('FATURACAO_AGT_ESTABELECIMENTO', 'SEDE'),\n"
    if ancora not in t: falhar('bloco agt do config não encontrado (a Fase 2a está aplicada?).')
    novo = ancora + """        'lote_maximo' => 30, // máx. de documentos por pedido registarFactura
        'max_tentativas' => (int) env('FATURACAO_AGT_MAX_TENTATIVAS', 5),
        'consulta_intervalo' => (int) env('FATURACAO_AGT_CONSULTA_INTERVALO', 60), // segundos entre consultas de estado
        'operation_type_padrao' => env('FATURACAO_AGT_OPERATION_TYPE', 'SG'), // tipo de operação das linhas (SG = serviço geral)
        'unidade_padrao' => 'UN',
        // Taxa de IVA (2 casas) => taxCode da AGT (NOR, INT, RED, OUT). Só as listadas são enviadas.
        'codigos_taxa' => ['14.00' => 'NOR'],
"""
    gravar(f, t.replace(ancora, novo, 1))

# ---------- bootstrap/app.php: registar o comando ----------
f = pathlib.Path('bootstrap/app.php')
t = ler(f)
if 'SincronizarAgtCommand' not in t:
    t, n = re.subn(r"(use Modules\\Faturacao\\Console\\SolicitarSerieAgtCommand;\n)", r"use Modules\\Faturacao\\Console\\SincronizarAgtCommand;\n\1", t, count=1)
    if n != 1: falhar('bootstrap/app.php sem o comando da Fase 2a.')
    t, n = re.subn(r"(        SolicitarSerieAgtCommand::class,\n)", r"\1        SincronizarAgtCommand::class,\n", t, count=1)
    if n != 1: falhar('lista de comandos de bootstrap/app.php não encontrada.')
    gravar(f, t)

# ---------- routes/console.php: agendar ----------
f = pathlib.Path('routes/console.php')
t = ler(f)
if 'agt-sincronizar' not in t:
    t, n = re.subn(r"(use Illuminate\\Support\\Facades\\Artisan;\n)", r"\1use Illuminate\\Support\\Facades\\Schedule;\n", t, count=1)
    if n != 1: falhar('routes/console.php sem o import de Artisan.')
    t = t.rstrip('\n') + """

// Comunicação com a AGT: envia os documentos pendentes e consulta o estado dos enviados.
// Só faz algo com FATURACAO_AGT_ATIVO=true. Requer o agendador (php artisan schedule:work / cron).
Schedule::command('faturacao:agt-sincronizar')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('faturacao.agt.ativo'));
"""
    gravar(f, t)

# ---------- README ----------
f = M / 'README.md'
t = ler(f)
if 'Envio à AGT' not in t:
    t += """

## Envio à AGT (Fase 2b)

Com `FATURACAO_AGT_ATIVO=true`, cada documento emitido (FT, NC, ND, RC) fica registado em
`agt_submissoes` na mesma transação da emissão. `php artisan faturacao:agt-sincronizar`
(agendado de minuto a minuto) envia os pendentes à AGT em lotes de 30 (`registarFactura`)
e consulta o estado dos enviados (`obterEstado`): `valido`, `invalido` (erros da AGT
guardados em `erros`), `rejeitado` ou `erro`. O que se envia é copiado do JWS guardado
no documento, por isso é igual ao que foi assinado.

Limitações atuais (os documentos nestes casos ficam em `erro`, sem bloquear os outros):
IVA 0% / isenções (falta o código do Anexo 6.4), recibos sem fatura (adiantamentos),
documentos em divisa, notas de crédito sem fatura original. `operationType` é `SG` para
todas as linhas e só a taxa de 14% (`NOR`) está mapeada em `agt.codigos_taxa`.
Um documento `invalido`/`rejeitado` só se corrige com um documento novo (regra E46) — fluxo ainda por fazer.
"""
    gravar(f, t)

print('Fase 2b: patches aplicados com sucesso.')

EOF_PATCH_2B

echo "== Sintaxe PHP =="
for f in $(git status --porcelain | awk '{print $2}' | grep '\.php$'); do php -l "$f" >/dev/null; done

echo "== Estilo (Pint) =="
vendor/bin/pint Modules/Faturacao bootstrap/app.php routes/console.php

echo "== Migração (base de dados local) =="
php artisan migrate --force || echo "AVISO: migrate falhou na BD local; os testes usam SQLite em memória e continuam."

echo "== Testes (todos) =="
php artisan test

echo "== Commit e push direto para main =="
git add -A
git commit -m "feat(faturacao): envio à AGT (registarFactura) e consulta de estado (obterEstado)" \
  -m "Fase 2b: tabela agt_submissoes (estado fora dos documentos imutáveis), construção do documento a partir do JWS assinado (linhas, impostos, referenceInfo, paymentReceipt), envio em lotes de 30, consulta de estado com jwsSignature, comando faturacao:agt-sincronizar agendado. Desligado por omissão (FATURACAO_AGT_ATIVO=false)."
git push origin main
echo "FASE 2b CONCLUÍDA e enviada para main."
)
