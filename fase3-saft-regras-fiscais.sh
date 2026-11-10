#!/usr/bin/env bash
# =============================================================================
# Saas1 — PR #33 — FASE 3: validar SAF-T (AO) e regras fiscais
# Branch: feat/faturacao-fiscal-core
#
# Correr na RAIZ do repositório (Codespace):   bash fase3-saft-regras-fiscais.sh
# Variáveis opcionais:  FORCE=1 (ignora a verificação da branch)
#                       SKIP_TESTS=1 (não corre os testes no fim)
#
# O que faz (não faz commit nem push):
#   1. SafTExportService: corrige o InvoiceType (todas as faturas saíam como "FT",
#      mesmo as FA); namespace do ficheiro (config ou targetNamespace do XSD);
#      classificarImposto() passa a public (fonte única da classificação fiscal).
#   2. SafTPreValidacao (novo): regras que NÃO dependem do XSD — lacunas de
#      numeração, cadeia de hash, totais vs linhas, recálculo do IVA, códigos de
#      taxa/isenção, config coerente. Nada disto é inventado: só verifica o que
#      o próprio sistema já gravou.
#   3. GerarSafTJob: corre a pré-validação antes de gerar; se falhar, NÃO publica.
#   4. SafTValidadorXsd: lê o targetNamespace do XSD oficial.
#   5. Comando  php artisan faturacao:saft-validar {empresa} {inicio} {fim} [--xsd=] [--guardar=]
#   6. Testes (SafTPreValidacaoTest) e docs/SAFT_VALIDACAO.md.
#
# O QUE ESTE SCRIPT NÃO FAZ: não traz o XSD oficial da AGT (não está no repositório
# e não o inventei). Sem ele, a conformidade estrutural continua por provar.
# =============================================================================
set -euo pipefail

MOD="Modules/Faturacao"
RAMO_ESPERADO="feat/faturacao-fiscal-core"

[ -f artisan ] && [ -d "$MOD/app/Services" ] || { echo "ERRO: corre este script na raiz do repositório Saas1."; exit 1; }

RAMO="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
if [ "$RAMO" != "$RAMO_ESPERADO" ] && [ "${FORCE:-0}" != "1" ]; then
  echo "ERRO: estás na branch '$RAMO'; esperava '$RAMO_ESPERADO'. (FORCE=1 para ignorar)"
  exit 1
fi

command -v python3 >/dev/null || { echo "ERRO: python3 é necessário para aplicar os patches."; exit 1; }

mkdir -p "$MOD/app/Services" "$MOD/app/Console" "$MOD/app/Jobs" "$MOD/tests/Feature" "$MOD/docs"

echo "==> [1/6] Ficheiros novos e reescritos"

# -----------------------------------------------------------------------------
cat > "$MOD/app/Services/SafTPreValidacao.php" <<'EOF_PREVALIDACAO'
<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Carbon;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\FaturaLinha;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Support\Dinheiro;

/**
 * Regras que se podem verificar SEM o XSD oficial: coerência interna dos documentos
 * emitidos no período. Passar aqui não significa "conforme ao SAF-T (AO)" — isso só
 * o XSD oficial (SafTValidadorXsd) e a AGT podem dizer. Falhar aqui significa que o
 * ficheiro nunca devia ser publicado, qualquer que seja o XSD.
 *
 * Todas as queries filtram explicitamente por empresa_id e ignoram a TenantScope
 * (mesma regra do SafTExportService), por isso funcionam em jobs e comandos.
 */
class SafTPreValidacao
{
    public const MAX_ERROS = 100;

    public function __construct(protected SafTExportService $exportador) {}

    /** @return list<string> problemas encontrados; vazio quando nada impede a geração */
    public function validar(Empresa $empresa, Carbon $inicio, Carbon $fim): array
    {
        $erros = $this->validarConfiguracao();

        if (trim((string) $empresa->nif) === '') {
            $erros[] = 'A empresa não tem NIF: o cabeçalho do SAF-T (AO) fica inválido.';
        }

        $de = $inicio->toDateString();
        $ate = $fim->toDateString();

        foreach ([Fatura::class, NotaCreditoDebito::class] as $modelo) {
            // serie_id => [número sequencial, hash, referência] do documento anterior
            $anterior = [];

            $documentos = $modelo::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('estado', 'emitida')
                ->whereBetween('data_emissao', [$de, $ate])
                ->with('linhas')
                ->orderBy('serie_id')
                ->orderBy('numero_sequencial')
                ->lazy(200);

            foreach ($documentos as $documento) {
                foreach ($this->verificarContinuidade($documento, $anterior) as $erro) {
                    $erros[] = $erro;
                }

                foreach ($this->verificarDocumento($documento) as $erro) {
                    $erros[] = $erro;
                }

                if (count($erros) >= self::MAX_ERROS) {
                    break 2;
                }
            }
        }

        return array_slice(array_values(array_unique($erros)), 0, self::MAX_ERROS);
    }

    /**
     * Configuração coerente: toda a taxa aceite na emissão tem de ter código SAF-T,
     * senão a emissão passa e a exportação rebenta a meio.
     *
     * @return list<string>
     */
    public function validarConfiguracao(): array
    {
        $erros = [];
        $codigos = (array) config('faturacao.agt.codigos_taxa', []);

        foreach ((array) config('faturacao.taxas_iva_permitidas', []) as $taxa) {
            if ((float) $taxa <= 0) {
                continue;
            }

            $chave = number_format((float) $taxa, 2, '.', '');
            $codigo = $codigos[$chave] ?? null;

            if (! is_string($codigo) || trim($codigo) === '') {
                $erros[] = "A taxa de IVA {$chave}% é aceite na emissão mas não tem código SAF-T (AO) em faturacao.agt.codigos_taxa.";
            }
        }

        return $erros;
    }

    /**
     * Numeração sem lacunas e cadeia de hash contínua dentro de cada série.
     * Os documentos têm de chegar ordenados por série e número.
     *
     * @param  array<int, array{0: int, 1: string, 2: string}>  $anterior
     * @return list<string>
     */
    public function verificarContinuidade(Fatura|NotaCreditoDebito $documento, array &$anterior): array
    {
        $erros = [];
        $serie = (int) $documento->serie_id;
        $numero = (int) $documento->numero_sequencial;
        $referencia = $this->referencia($documento);

        if (isset($anterior[$serie])) {
            [$numeroAnterior, $hashAnterior, $referenciaAnterior] = $anterior[$serie];

            if ($numero === $numeroAnterior) {
                $erros[] = "Número sequencial repetido na série #{$serie}: {$referencia} e {$referenciaAnterior}.";
            } elseif ($numero !== $numeroAnterior + 1) {
                $de = $numeroAnterior + 1;
                $ate = $numero - 1;
                $erros[] = "Lacuna na numeração da série #{$serie}: faltam os números {$de} a {$ate} entre {$referenciaAnterior} e {$referencia}.";
            } elseif ((string) $documento->hash_anterior !== $hashAnterior) {
                $erros[] = "Cadeia de hash interrompida na série #{$serie}: o hash anterior de {$referencia} não é o hash de {$referenciaAnterior}.";
            }
        }

        $anterior[$serie] = [$numero, (string) $documento->hash, $referencia];

        return $erros;
    }

    /**
     * Campos obrigatórios, totais do documento vs soma das linhas, e cada linha.
     *
     * @return list<string>
     */
    public function verificarDocumento(Fatura|NotaCreditoDebito $documento): array
    {
        $erros = [];
        $referencia = $this->referencia($documento);

        if (trim((string) $documento->numero_documento) === '') {
            $erros[] = "{$referencia}: sem número de documento.";
        }

        if (trim((string) $documento->hash) === '') {
            $erros[] = "{$referencia}: sem hash (assinatura); não pode entrar no SAF-T.";
        }

        if ($documento->chave_versao === null) {
            $erros[] = "{$referencia}: sem versão da chave de assinatura.";
        }

        if ($documento->data_hora_sistema === null) {
            $erros[] = "{$referencia}: sem data/hora de sistema (SystemEntryDate).";
        }

        $linhas = $documento->linhas;

        if ($linhas->isEmpty()) {
            $erros[] = "{$referencia}: documento emitido sem linhas.";

            return $erros;
        }

        try {
            $campos = ['valor_sem_iva' => 'valor sem IVA', 'valor_iva' => 'IVA', 'valor_total' => 'total'];

            foreach ($campos as $campo => $rotulo) {
                $doc = Dinheiro::centimos($documento->{$campo});
                $soma = (int) $linhas->sum(fn ($l) => Dinheiro::centimos($l->{$campo}));

                if ($doc !== $soma) {
                    $erros[] = "{$referencia}: {$rotulo} do documento (".Dinheiro::formatar($doc).') difere da soma das linhas ('.Dinheiro::formatar($soma).').';
                }
            }

            if (Dinheiro::centimos($documento->valor_sem_iva) + Dinheiro::centimos($documento->valor_iva) !== Dinheiro::centimos($documento->valor_total)) {
                $erros[] = "{$referencia}: valor sem IVA + IVA não é igual ao total.";
            }
        } catch (\InvalidArgumentException $e) {
            $erros[] = "{$referencia}: {$e->getMessage()}";
        }

        foreach ($linhas->values() as $indice => $linha) {
            foreach ($this->verificarLinha($documento, $linha, $referencia, $indice + 1) as $erro) {
                $erros[] = $erro;
            }
        }

        return $erros;
    }

    /** @return list<string> */
    protected function verificarLinha(Fatura|NotaCreditoDebito $documento, object $linha, string $referencia, int $numero): array
    {
        $erros = [];

        // Mesma classificação que o exportador usa: se ela rebenta, o SAF-T nunca seria gerado.
        try {
            [, , $motivo] = $this->exportador->classificarImposto($documento, $linha);

            if ($motivo !== null && app()->isProduction() && ! config('faturacao.motivos_isencao_confirmados')) {
                $erros[] = "{$referencia} (linha {$numero}): usa o código {$motivo}, mas a tabela de motivos de isenção ainda não foi confirmada contra o anexo oficial (FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS).";
            }
        } catch (\RuntimeException $e) {
            $erros[] = "{$referencia} (linha {$numero}): {$e->getMessage()}";
        }

        // Os valores gravados têm de bater com o recálculo (quantidade × preço; IVA por excesso).
        if ($linha instanceof FaturaLinha) {
            try {
                $v = Dinheiro::calcularLinha($linha->quantidade, $linha->preco_unitario, $linha->taxa_iva);

                if (Dinheiro::centimos($v['sem_iva']) !== Dinheiro::centimos($linha->valor_sem_iva)
                    || Dinheiro::centimos($v['iva']) !== Dinheiro::centimos($linha->valor_iva)
                    || Dinheiro::centimos($v['total']) !== Dinheiro::centimos($linha->valor_total)) {
                    $erros[] = "{$referencia} (linha {$numero}): os valores gravados não correspondem ao recálculo (quantidade × preço, IVA arredondado por excesso).";
                }
            } catch (\InvalidArgumentException $e) {
                $erros[] = "{$referencia} (linha {$numero}): {$e->getMessage()}";
            }
        }

        return $erros;
    }

    protected function referencia(Fatura|NotaCreditoDebito $documento): string
    {
        $numero = trim((string) $documento->numero_documento);

        return $numero !== '' ? $numero : 'documento #'.$documento->getKey();
    }
}
EOF_PREVALIDACAO

# -----------------------------------------------------------------------------
cat > "$MOD/app/Services/SafTValidadorXsd.php" <<'EOF_VALIDADOR'
<?php

namespace Modules\Faturacao\Services;

use DOMDocument;

/**
 * Valida o XML do SAF-T (AO) contra o XSD oficial.
 *
 * "XML bem formado" não é "XML conforme ao XSD", e nenhum dos dois é "aceite
 * pela AGT": esta classe cobre só o segundo passo, e só quando o XSD oficial
 * estiver presente em `faturacao.saft_xsd_path`.
 */
class SafTValidadorXsd
{
    public function caminhoXsd(): string
    {
        return (string) config('faturacao.saft_xsd_path');
    }

    public function disponivel(): bool
    {
        $caminho = $this->caminhoXsd();

        return $caminho !== '' && is_file($caminho);
    }

    /**
     * targetNamespace declarado no XSD oficial (null se não houver XSD ou namespace).
     * O exportador usa-o como xmlns do ficheiro, em vez de se adivinhar um valor.
     */
    public function namespaceAlvo(): ?string
    {
        if (! $this->disponivel()) {
            return null;
        }

        $anterior = libxml_use_internal_errors(true);

        try {
            $doc = new DOMDocument;

            if (! $doc->load($this->caminhoXsd()) || $doc->documentElement === null) {
                return null;
            }

            $namespace = trim($doc->documentElement->getAttribute('targetNamespace'));

            return $namespace !== '' ? $namespace : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    /**
     * @return list<string> erros de validação; vazio quando o XML é conforme ao XSD
     *
     * @throws \RuntimeException se o XSD oficial não estiver disponível
     */
    public function validar(string $xml): array
    {
        if (! $this->disponivel()) {
            throw new \RuntimeException('XSD oficial do SAF-T (AO) não encontrado em "'.$this->caminhoXsd().'".');
        }

        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $doc = new DOMDocument;

            if (! $doc->loadXML($xml)) {
                return $this->erros('XML mal formado');
            }

            if ($doc->schemaValidate($this->caminhoXsd())) {
                return [];
            }

            return $this->erros('Validação XSD falhou');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    /** @return list<string> */
    protected function erros(string $mensagemPorOmissao): array
    {
        $erros = [];

        foreach (libxml_get_errors() as $erro) {
            $erros[] = trim($erro->message).' (linha '.$erro->line.')';
        }

        return $erros !== [] ? array_slice($erros, 0, 50) : [$mensagemPorOmissao.'.'];
    }
}
EOF_VALIDADOR

# -----------------------------------------------------------------------------
cat > "$MOD/app/Jobs/GerarSafTJob.php" <<'EOF_JOB'
<?php

namespace Modules\Faturacao\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Services\SafTExportService;
use Modules\Faturacao\Services\SafTPreValidacao;
use Modules\Faturacao\Services\SafTValidadorXsd;
use Throwable;

/**
 * Corre sempre em fila. ATENÇÃO: DB_QUEUE_RETRY_AFTER tem de ser MAIOR que
 * $timeout (ver .env.example), senão o job é reentregue enquanto ainda corre.
 *
 * "Job terminou" não é "ficheiro fiscalmente válido". Cada geração fica com um
 * estado (ficheiro .json em `meta/`):
 *   - validado_xsd              conforme ao XSD oficial → publicado
 *   - gerado_sem_validacao      XSD oficial ausente → publicado só fora de produção
 *   - rejeitado_xsd             não conforme ao XSD → NÃO publicado (pasta rejeitados/)
 *   - rejeitado_regras_fiscais  falhou a pré-validação (lacunas, hash, totais, códigos
 *                               fiscais) → nem sequer é gerado; fica só o diagnóstico
 * A versão publicada anterior do mesmo período vai para historico/ em vez de
 * ser substituída.
 */
class GerarSafTJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const ESTADO_VALIDADO = 'validado_xsd';

    public const ESTADO_SEM_VALIDACAO = 'gerado_sem_validacao';

    public const ESTADO_REJEITADO = 'rejeitado_xsd';

    public const ESTADO_REJEITADO_REGRAS = 'rejeitado_regras_fiscais';

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(
        protected Empresa $empresa,
        protected string $dataInicio,
        protected string $dataFim,
    ) {}

    public function uniqueId(): string
    {
        return "saft:{$this->empresa->id}:{$this->dataInicio}:{$this->dataFim}";
    }

    public function handle(SafTExportService $safTExportService, SafTValidadorXsd $validador, ?SafTPreValidacao $regras = null): void
    {
        $regras ??= app(SafTPreValidacao::class);

        $inicio = Carbon::createFromFormat('Y-m-d', $this->dataInicio)->startOfDay();
        $fim = Carbon::createFromFormat('Y-m-d', $this->dataFim)->endOfDay();

        $errosRegras = $regras->validar($this->empresa, $inicio, $fim);

        if ($errosRegras !== []) {
            $this->registarRejeicaoPorRegras($inicio, $fim, $errosRegras);

            throw new \RuntimeException(
                'O SAF-T não foi gerado: '.count($errosRegras).' problema(s) de regras fiscais ou de coerência dos documentos (ver o .json em rejeitados/). Primeiro: '.$errosRegras[0]
            );
        }

        $xml = $safTExportService->gerar($this->empresa, $inicio, $fim);

        $erros = [];

        if ($validador->disponivel()) {
            $erros = $validador->validar($xml);
            $estado = $erros === [] ? self::ESTADO_VALIDADO : self::ESTADO_REJEITADO;
        } else {
            $estado = self::ESTADO_SEM_VALIDACAO;
        }

        $publicar = $estado === self::ESTADO_VALIDADO
            || ($estado === self::ESTADO_SEM_VALIDACAO && ! app()->isProduction());

        $disco = Storage::disk('local');
        $dir = "faturacao/saft/{$this->empresa->id}";
        $nome = "SAFT_{$inicio->toDateString()}_{$fim->toDateString()}";
        $agora = now()->format('Ymd_His');

        if ($publicar) {
            // Guarda a versão anterior do mesmo período em vez de a substituir.
            $versoes = [
                ["{$dir}/{$nome}.xml", "{$dir}/historico/{$nome}_{$agora}.xml"],
                ["{$dir}/meta/{$nome}.json", "{$dir}/historico/{$nome}_{$agora}.json"],
            ];

            foreach ($versoes as [$de, $para]) {
                if ($disco->exists($de)) {
                    $disco->move($de, $para);
                }
            }

            $caminho = "{$dir}/{$nome}.xml";
            $caminhoMeta = "{$dir}/meta/{$nome}.json";
        } else {
            $caminho = "{$dir}/rejeitados/{$nome}_{$agora}.xml";
            $caminhoMeta = "{$dir}/rejeitados/{$nome}_{$agora}.json";
        }

        $disco->put($caminho, $xml);
        $disco->put($caminhoMeta, (string) json_encode([
            'empresa_id' => $this->empresa->id,
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()],
            'gerado_em' => now()->toIso8601String(),
            'estado' => $estado,
            'sha256' => hash('sha256', $xml),
            'tamanho_bytes' => strlen($xml),
            'numero_certificado' => (string) config('faturacao.saft_numero_certificado', '0'),
            'xsd' => $validador->disponivel() ? basename($validador->caminhoXsd()) : null,
            'erros' => $erros,
            'erros_regras' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        Log::info('SAF-T (AO) gerado', [
            'empresa_id' => $this->empresa->id,
            'caminho' => $caminho,
            'estado' => $estado,
            'tamanho_bytes' => strlen($xml),
        ]);

        if ($estado === self::ESTADO_REJEITADO) {
            throw new \RuntimeException(
                'O SAF-T gerado não é conforme ao XSD oficial ('.count($erros)." erro(s)); guardado em {$caminho} só para diagnóstico."
            );
        }

        if (! $publicar) {
            throw new \RuntimeException(
                "Sem o XSD oficial em \"{$validador->caminhoXsd()}\" o SAF-T não pode ser marcado como pronto em produção; guardado em {$caminho} só para diagnóstico."
            );
        }
    }

    /** @param  list<string>  $erros */
    protected function registarRejeicaoPorRegras(Carbon $inicio, Carbon $fim, array $erros): void
    {
        $agora = now()->format('Ymd_His');
        $caminho = "faturacao/saft/{$this->empresa->id}/rejeitados/SAFT_{$inicio->toDateString()}_{$fim->toDateString()}_{$agora}.json";

        Storage::disk('local')->put($caminho, (string) json_encode([
            'empresa_id' => $this->empresa->id,
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()],
            'gerado_em' => now()->toIso8601String(),
            'estado' => self::ESTADO_REJEITADO_REGRAS,
            'erros_regras' => $erros,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        Log::warning('SAF-T (AO) não gerado: falhou a pré-validação fiscal', [
            'empresa_id' => $this->empresa->id,
            'periodo' => "{$inicio->toDateString()} a {$fim->toDateString()}",
            'erros' => count($erros),
            'diagnostico' => $caminho,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Falha ao gerar SAF-T (AO)', [
            'empresa_id' => $this->empresa->id,
            'periodo' => "{$this->dataInicio} a {$this->dataFim}",
            'erro' => $e->getMessage(),
        ]);
    }
}
EOF_JOB

# -----------------------------------------------------------------------------
cat > "$MOD/app/Console/ValidarSafTCommand.php" <<'EOF_COMANDO'
<?php

namespace Modules\Faturacao\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Services\SafTExportService;
use Modules\Faturacao\Services\SafTPreValidacao;
use Modules\Faturacao\Services\SafTValidadorXsd;

/**
 * Corre as duas camadas de validação do SAF-T (AO) sem publicar nada:
 *   1. regras fiscais e coerência dos documentos (SafTPreValidacao);
 *   2. conformidade com o XSD oficial (SafTValidadorXsd), se o XSD existir.
 * Sai com erro se alguma falhar OU se o XSD oficial não estiver disponível:
 * "sem erros" sem XSD não é "validado".
 */
class ValidarSafTCommand extends Command
{
    protected $signature = 'faturacao:saft-validar
        {empresa : ID da empresa}
        {inicio : Data de início (AAAA-MM-DD)}
        {fim : Data de fim (AAAA-MM-DD)}
        {--xsd= : Caminho do XSD oficial (por omissão, faturacao.saft_xsd_path)}
        {--guardar= : Guarda o XML gerado neste caminho, mesmo que inválido (diagnóstico)}';

    protected $description = 'Valida o SAF-T (AO) de uma empresa e período: regras fiscais + XSD oficial (não publica nada)';

    public function handle(SafTExportService $exportador, SafTPreValidacao $regras, SafTValidadorXsd $validador): int
    {
        $inicio = $this->data((string) $this->argument('inicio'));
        $fim = $this->data((string) $this->argument('fim'));

        if ($inicio === null || $fim === null) {
            $this->error('Datas inválidas: usa o formato AAAA-MM-DD.');

            return self::FAILURE;
        }

        $fim = $fim->endOfDay();

        if (($erroPeriodo = $exportador->validarPeriodo($inicio, $fim)) !== null) {
            $this->error($erroPeriodo);

            return self::FAILURE;
        }

        $empresa = Empresa::query()->find((int) $this->argument('empresa'));

        if ($empresa === null) {
            $this->error('Empresa não encontrada.');

            return self::FAILURE;
        }

        if ($this->option('xsd')) {
            config(['faturacao.saft_xsd_path' => (string) $this->option('xsd')]);
        }

        $this->line("Empresa #{$empresa->id}, período {$inicio->toDateString()} a {$fim->toDateString()}");

        $falhou = false;

        $errosRegras = $regras->validar($empresa, $inicio, $fim);

        if ($errosRegras !== []) {
            $falhou = true;
            $this->table(['#', 'Regras fiscais / coerência'], $this->linhas($errosRegras));
        } else {
            $this->info('Regras fiscais e coerência dos documentos: sem problemas.');
        }

        try {
            $xml = $exportador->gerar($empresa, $inicio, $fim);
        } catch (\RuntimeException $e) {
            $this->error('Não foi possível gerar o ficheiro: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('guardar')) {
            file_put_contents((string) $this->option('guardar'), $xml);
            $this->line('XML guardado em '.$this->option('guardar'));
        }

        if (! $validador->disponivel()) {
            $this->warn('XSD oficial não encontrado em "'.$validador->caminhoXsd().'": a conformidade estrutural NÃO foi verificada.');

            return self::FAILURE;
        }

        $this->line('XSD: '.$validador->caminhoXsd().' (targetNamespace: '.($validador->namespaceAlvo() ?? 'nenhum').')');

        $errosXsd = $validador->validar($xml);

        if ($errosXsd !== []) {
            $falhou = true;
            $this->table(['#', 'Erros do XSD'], $this->linhas($errosXsd));
        } else {
            $this->info('Conforme ao XSD indicado.');
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }

    protected function data(string $texto): ?Carbon
    {
        try {
            $data = Carbon::createFromFormat('Y-m-d', $texto);
        } catch (\Throwable) {
            return null;
        }

        // Rejeita datas que o Carbon "corrige" em silêncio (ex.: 2026-02-31).
        return $data && $data->format('Y-m-d') === $texto ? $data->startOfDay() : null;
    }

    /**
     * @param  list<string>  $erros
     * @return list<array{0: int, 1: string}>
     */
    protected function linhas(array $erros): array
    {
        $linhas = [];

        foreach (array_values($erros) as $i => $erro) {
            $linhas[] = [$i + 1, $erro];
        }

        return $linhas;
    }
}
EOF_COMANDO

# -----------------------------------------------------------------------------
cat > "$MOD/tests/Feature/SafTPreValidacaoTest.php" <<'EOF_TESTE'
<?php

namespace Modules\Faturacao\Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\FaturaLinha;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\SafTExportService;
use Modules\Faturacao\Services\SafTPreValidacao;
use Tests\TestCase;

/** Fase 3: regras fiscais do SAF-T que não dependem do XSD, tipo de documento e namespace. */
class SafTPreValidacaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/saft-pre-'.uniqid();
        mkdir($this->tmp);
        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->tmp.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmp);
        });

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($this->tmp.'/chave.pem', $pem);

        config([
            'faturacao.chave_privada_path' => $this->tmp.'/chave.pem',
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Fase3', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    /** @param  array<string, mixed>  $extra */
    protected function linhaOk(array $extra = []): array
    {
        return array_merge([
            'descricao' => 'Serviço',
            'quantidade' => '1.000',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14.00',
            'valor_sem_iva' => '100.00',
            'valor_iva' => '14.00',
            'valor_total' => '114.00',
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  list<array<string, mixed>>|null  $linhas
     */
    protected function fatura(array $atributos = [], ?array $linhas = null): Fatura
    {
        $fatura = new Fatura(array_merge([
            'empresa_id' => 1,
            'serie_id' => 1,
            'tipo_documento' => 'FT',
            'numero_sequencial' => 1,
            'numero_documento' => 'FT A2026/1',
            'estado' => 'emitida',
            'valor_sem_iva' => '100.00',
            'valor_iva' => '14.00',
            'valor_total' => '114.00',
            'hash' => 'h1',
            'hash_anterior' => null,
            'chave_versao' => 1,
        ], $atributos));

        $fatura->data_hora_sistema = now();
        $fatura->setRelation('linhas', collect(array_map(
            fn (array $l) => new FaturaLinha($l),
            $linhas ?? [$this->linhaOk()]
        )));

        return $fatura;
    }

    protected function servico(): SafTPreValidacao
    {
        return app(SafTPreValidacao::class);
    }

    protected function xml(): string
    {
        return app(SafTExportService::class)->gerar($this->empresa, now()->startOfYear(), now()->endOfYear());
    }

    protected function emitir(string $descricao, bool $adiantamento = false): Fatura
    {
        $faturas = app(FaturaService::class);
        $linhas = [['descricao' => $descricao, 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14]];

        $rascunho = $adiantamento
            ? $faturas->criarRascunhoAdiantamento($this->empresa, $this->cliente, $linhas)
            : $faturas->criarRascunho($this->empresa, $this->cliente, $linhas);

        return $faturas->emitir($rascunho);
    }

    // --- Regras sobre um documento -------------------------------------

    public function test_documento_consistente_nao_tem_erros(): void
    {
        $this->assertSame([], $this->servico()->verificarDocumento($this->fatura()));
    }

    public function test_totais_que_nao_batem_com_as_linhas_sao_detetados(): void
    {
        $erros = $this->servico()->verificarDocumento($this->fatura(['valor_total' => '120.00']));

        $this->assertNotEmpty($erros);
        $this->assertStringContainsString('difere da soma das linhas', implode(' ', $erros));
    }

    public function test_documento_sem_hash_e_detetado(): void
    {
        $erros = $this->servico()->verificarDocumento($this->fatura(['hash' => '']));

        $this->assertStringContainsString('sem hash', implode(' ', $erros));
    }

    public function test_valores_da_linha_que_nao_batem_com_o_recalculo_sao_detetados(): void
    {
        // 100.00 a 14% => IVA 14.00; a linha diz 13.00 (e o documento acompanha, para isolar o erro da linha).
        $linha = $this->linhaOk(['valor_iva' => '13.00', 'valor_total' => '113.00']);
        $erros = $this->servico()->verificarDocumento($this->fatura(['valor_iva' => '13.00', 'valor_total' => '113.00'], [$linha]));

        $this->assertStringContainsString('recálculo', implode(' ', $erros));
    }

    public function test_taxa_sem_codigo_saft_e_detetada_com_mensagem_clara(): void
    {
        config(['faturacao.agt.codigos_taxa' => []]);

        $erros = $this->servico()->verificarDocumento($this->fatura());

        $this->assertStringContainsString('código SAF-T', implode(' ', $erros));
    }

    // --- Continuidade da série ----------------------------------------

    public function test_sequencia_correta_com_cadeia_de_hash_nao_tem_erros(): void
    {
        $anterior = [];

        $this->assertSame([], $this->servico()->verificarContinuidade($this->fatura(), $anterior));
        $this->assertSame([], $this->servico()->verificarContinuidade(
            $this->fatura(['numero_sequencial' => 2, 'numero_documento' => 'FT A2026/2', 'hash' => 'h2', 'hash_anterior' => 'h1']),
            $anterior
        ));
    }

    public function test_lacuna_na_numeracao_e_detetada(): void
    {
        $anterior = [];
        $this->servico()->verificarContinuidade($this->fatura(), $anterior);

        $erros = $this->servico()->verificarContinuidade(
            $this->fatura(['numero_sequencial' => 3, 'numero_documento' => 'FT A2026/3', 'hash' => 'h3', 'hash_anterior' => 'h2']),
            $anterior
        );

        $this->assertStringContainsString('Lacuna', implode(' ', $erros));
    }

    public function test_cadeia_de_hash_interrompida_e_detetada(): void
    {
        $anterior = [];
        $this->servico()->verificarContinuidade($this->fatura(), $anterior);

        $erros = $this->servico()->verificarContinuidade(
            $this->fatura(['numero_sequencial' => 2, 'numero_documento' => 'FT A2026/2', 'hash' => 'h2', 'hash_anterior' => 'outro']),
            $anterior
        );

        $this->assertStringContainsString('Cadeia de hash interrompida', implode(' ', $erros));
    }

    // --- Configuração ----------------------------------------------------

    public function test_taxa_permitida_sem_codigo_e_erro_de_configuracao(): void
    {
        config(['faturacao.taxas_iva_permitidas' => [14.0, 7.0]]);

        $erros = $this->servico()->validarConfiguracao();

        $this->assertCount(1, $erros);
        $this->assertStringContainsString('7.00', $erros[0]);
    }

    // --- Com base de dados -----------------------------------------------

    public function test_periodo_sem_documentos_nao_tem_erros(): void
    {
        $this->assertSame([], $this->servico()->validar($this->empresa, now()->startOfYear(), now()->endOfYear()));
    }

    public function test_documentos_emitidos_pelo_sistema_passam_a_pre_validacao(): void
    {
        $this->emitir('Primeira');
        $this->emitir('Segunda');

        $this->assertSame([], $this->servico()->validar($this->empresa, now()->startOfYear(), now()->endOfYear()));
    }

    public function test_fatura_de_adiantamento_sai_no_saft_com_o_seu_tipo(): void
    {
        $this->emitir('Venda normal');
        $this->emitir('Adiantamento', adiantamento: true);

        $dom = new DOMDocument;
        $dom->loadXML($this->xml());
        $xp = new DOMXPath($dom);

        $this->assertSame(1, (int) $xp->evaluate('count(//Invoice[InvoiceType="FT"])'));
        $this->assertSame(1, (int) $xp->evaluate('count(//Invoice[InvoiceType="FA"])'));
    }

    // --- Namespace -------------------------------------------------------

    public function test_sem_namespace_configurado_o_ficheiro_fica_como_antes(): void
    {
        $this->emitir('Venda');

        $this->assertStringContainsString('<AuditFile>', $this->xml());
    }

    public function test_namespace_configurado_aplica_se_a_todo_o_ficheiro(): void
    {
        config(['faturacao.saft_namespace' => 'urn:teste:saft']);
        $this->emitir('Venda');

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($this->xml()));

        $this->assertSame('urn:teste:saft', $dom->documentElement->namespaceURI);
        $this->assertSame(1, $dom->getElementsByTagNameNS('urn:teste:saft', 'Invoice')->length);
        $this->assertSame(1, $dom->getElementsByTagNameNS('urn:teste:saft', 'Header')->length);
    }

    public function test_namespace_com_caracteres_invalidos_e_recusado(): void
    {
        config(['faturacao.saft_namespace' => 'urn:"><x']);

        $this->expectException(\RuntimeException::class);
        $this->xml();
    }
}
EOF_TESTE

# -----------------------------------------------------------------------------
cat > "$MOD/docs/SAFT_VALIDACAO.md" <<'EOF_DOC'
# Validação do SAF-T (AO) — fase 3

## O que está provado e o que não está

| Camada | Quem faz | Estado |
|---|---|---|
| Coerência interna dos documentos (totais, IVA, hash, numeração, códigos fiscais) | `SafTPreValidacao` | ✅ automática; bloqueia a geração |
| Conformidade estrutural com o XSD oficial | `SafTValidadorXsd` | ⛔ **por provar**: o XSD oficial não está no repositório |
| Aceitação pela AGT | homologação | ⛔ por fazer |

"Sem erros" na primeira camada **não** significa SAF-T (AO) válido.

## Como validar contra o XSD oficial

1. Obtém o XSD oficial nas especificações da AGT e guarda-o em
   `storage/app/faturacao/saft/SAF-T-AO.xsd` (ou define `FATURACAO_SAFT_XSD_PATH`).
2. Corre:

   ```bash
   php artisan faturacao:saft-validar <empresa_id> 2026-01-01 2026-12-31 --guardar=/tmp/saft.xml
   # ou, com outro XSD:  --xsd=/caminho/SAF-T-AO.xsd
   ```
3. O comando devolve erro se faltar o XSD, se falharem as regras fiscais ou se o XML não for
   conforme. Os erros do XSD indicam a linha do XML (`--guardar` para o inspecionar).

## Namespace

O `xmlns` do ficheiro é, por ordem: `faturacao.saft_namespace` (`FATURACAO_SAFT_NAMESPACE`) ou o
`targetNamespace` do XSD oficial, se existir. Sem nenhum dos dois, o ficheiro não leva namespace.
Assume-se `elementFormDefault="qualified"`; se o XSD oficial for `unqualified`, o erro aparece na validação.

## Estrutura ainda provisória (a fechar com o XSD)

O cabeçalho, os `MasterFiles` e os blocos por tipo de documento foram escritos sem o XSD à frente.
Os erros devolvidos pelo comando acima são a lista de trabalho para os alinhar. Pontos conhecidos:
`CompanyAddress` plano, elementos obrigatórios do cabeçalho eventualmente em falta, recibos
(`RC`/`RG`) ainda não exportados (fase 2), `InvoiceType` `FA` dentro de `SalesInvoices` (confirmar na enumeração do XSD).

## O que continua fora desta fase

Regimes de IVA, diferenciação por região, retenções na fonte e tabelas fiscais oficiais exigem texto legal
em vigor (Decreto Presidencial 71/25, Decreto Executivo 683/25) e confirmação do contabilista/AGT;
não foram inventados. Ver `MATRIZ_REQUISITOS_FISCAIS.md`.
EOF_DOC

echo "==> [2/6] Patches nos ficheiros existentes"

python3 - <<'PY'
import pathlib, sys

def patch(path, old, new):
    p = pathlib.Path(path)
    t = p.read_text(encoding='utf-8')
    if new in t:
        print(f'  = já aplicado: {path}')
        return
    if t.count(old) != 1:
        sys.exit(f'ERRO: âncora não encontrada (ou ambígua) em {path}:\n{old}\n'
                 '(a branch mudou? aplica este patch à mão)')
    p.write_text(t.replace(old, new, 1), encoding='utf-8')
    print(f'  + {path}')

EXP = 'Modules/Faturacao/app/Services/SafTExportService.php'

# 1. InvoiceType: cada fatura sai com o seu próprio tipo (FT, FA, ...), não "FT" fixo.
patch(EXP,
      "$vendas->appendChild($this->documento($doc, 'FT', $f, 'CreditAmount', $impostosUsados));",
      "$vendas->appendChild($this->documento($doc, $f->tipoDocumentoFiscal(), $f, 'CreditAmount', $impostosUsados));")

# 2. Namespace do ficheiro (xmlns injetado na raiz: os filhos sem prefixo herdam-no).
patch(EXP,
      "        return $doc->saveXML();",
      """        $xml = (string) $doc->saveXML();
        $namespace = $this->namespaceDoFicheiro();

        if ($namespace !== null) {
            $xml = preg_replace(
                '/<AuditFile>/',
                '<AuditFile xmlns="'.htmlspecialchars($namespace, ENT_XML1 | ENT_QUOTES, 'UTF-8').'">',
                $xml,
                1
            ) ?? $xml;
        }

        return $xml;""")

patch(EXP,
      "    /** Em produção, o SAF-T fica bloqueado enquanto não houver número de certificação AGT. */",
      """    /** xmlns do ficheiro: o configurado ou, se houver XSD oficial, o seu targetNamespace. Null = sem namespace. */
    protected function namespaceDoFicheiro(): ?string
    {
        $configurado = trim((string) config('faturacao.saft_namespace', ''));
        $namespace = $configurado !== '' ? $configurado : app(SafTValidadorXsd::class)->namespaceAlvo();

        if ($namespace === null || $namespace === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9:._\\-\\/]+$/', $namespace)) {
            throw new \\RuntimeException('O namespace do SAF-T (AO) contém caracteres inválidos.');
        }

        return $namespace;
    }

    /** Em produção, o SAF-T fica bloqueado enquanto não houver número de certificação AGT. */""")

# 3. A pré-validação reutiliza a classificação fiscal do exportador (fonte única).
patch(EXP,
      "    protected function classificarImposto(Fatura|NotaCreditoDebito $documento, object $linha): array",
      "    public function classificarImposto(Fatura|NotaCreditoDebito $documento, object $linha): array")

# 4. Configuração do namespace.
patch('Modules/Faturacao/config/config.php',
      "    'saft_xsd_path' => env('FATURACAO_SAFT_XSD_PATH') ?: storage_path('app/faturacao/saft/SAF-T-AO.xsd'),",
      """    'saft_xsd_path' => env('FATURACAO_SAFT_XSD_PATH') ?: storage_path('app/faturacao/saft/SAF-T-AO.xsd'),

    /*
    | xmlns do elemento raiz do SAF-T (AO). Vazio por omissão: nesse caso usa-se o targetNamespace do
    | XSD oficial, se estiver presente. Só preenche se o confirmares no XSD/especificação da AGT.
    */
    'saft_namespace' => env('FATURACAO_SAFT_NAMESPACE') ?: null,""")

# 5. Registo do comando (idempotente se o módulo já os descobrir automaticamente).
patch('Modules/Faturacao/app/Providers/FaturacaoServiceProvider.php',
      "        $this->app->register(RouteServiceProvider::class);\n",
      """        $this->app->register(RouteServiceProvider::class);

        $this->commands([\\Modules\\Faturacao\\Console\\ValidarSafTCommand::class]);
""")
PY

echo "==> [3/6] Verificação de sintaxe PHP"
if command -v php >/dev/null; then
  for f in \
    "$MOD/app/Services/SafTPreValidacao.php" \
    "$MOD/app/Services/SafTValidadorXsd.php" \
    "$MOD/app/Services/SafTExportService.php" \
    "$MOD/app/Jobs/GerarSafTJob.php" \
    "$MOD/app/Console/ValidarSafTCommand.php" \
    "$MOD/app/Providers/FaturacaoServiceProvider.php" \
    "$MOD/config/config.php" \
    "$MOD/tests/Feature/SafTPreValidacaoTest.php"; do
    php -l "$f" >/dev/null && echo "  ok  $f"
  done
else
  echo "  (php não encontrado; salto a verificação)"
fi

echo "==> [4/6] Formatação (Pint), se existir"
if [ -x vendor/bin/pint ]; then
  vendor/bin/pint \
    "$MOD/app/Services/SafTPreValidacao.php" \
    "$MOD/app/Services/SafTValidadorXsd.php" \
    "$MOD/app/Services/SafTExportService.php" \
    "$MOD/app/Jobs/GerarSafTJob.php" \
    "$MOD/app/Console/ValidarSafTCommand.php" \
    "$MOD/tests/Feature/SafTPreValidacaoTest.php" || true
fi

echo "==> [5/6] Testes"
if [ "${SKIP_TESTS:-0}" = "1" ]; then
  echo "  (SKIP_TESTS=1)"
else
  php artisan config:clear >/dev/null 2>&1 || true
  php artisan test --filter='SafT|GerarSafTJob|ExportacaoSafT'
fi

echo "==> [6/6] Estado do Git (nada foi commitado)"
git status --short

cat <<'MSG'

Concluído. Próximos passos:
  1. Revê:  git diff
  2. Para provar a conformidade estrutural, falta o XSD oficial da AGT:
       guarda-o em storage/app/faturacao/saft/SAF-T-AO.xsd e corre
       php artisan faturacao:saft-validar <empresa_id> 2026-01-01 2026-12-31 --guardar=/tmp/saft.xml
     Os erros que sair são a lista de trabalho para alinhar o cabeçalho/MasterFiles.
  3. Se estiver tudo bem:  git add -A && git commit -m "fase 3: pré-validação fiscal do SAF-T, InvoiceType e namespace"
MSG
