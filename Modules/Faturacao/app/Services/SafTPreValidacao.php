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
