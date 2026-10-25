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

        // Correção de um documento rejeitado pela AGT: é um documento NOVO (número novo, regra E46)
        // com documentStatus "C" e o número do documento rejeitado em rejectedDocumentNo.
        $rejeitado = $documento->getAttribute('rejeitado_documento_no');
        $correcao = is_string($rejeitado) && $rejeitado !== '' ? ['rejectedDocumentNo' => $rejeitado] : [];

        $resultado = [
            'documentNo' => $assinado['documentNo'],
            'documentStatus' => $correcao === [] ? 'N' : 'C',
            ...$correcao,
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
        // Nota de débito: não liquida IVA (faturacao.nota_debito_liquida_iva); as linhas seguem sem
        // bloco "taxes". CONFIRMAR em homologação que a AGT aceita linhas sem esse bloco.
        $semImposto = $documento instanceof NotaCreditoDebito
            && $documento->tipo === 'debito'
            && ! config('faturacao.nota_debito_liquida_iva', false);
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
            $produto = ($linha->produto_id ?? null) ? Produto::withoutGlobalScopes()->where('empresa_id', $documento->empresa_id)->find($linha->produto_id) : null;
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

            $impostos = $this->impostos($documento, $linha, $semImposto);

            if ($impostos !== []) {
                $item['taxes'] = $impostos;
            }

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

    /**
     * Impostos da linha.
     *  - Nota de débito sem imposto: por omissão a linha segue SEM bloco "taxes" (CONFIRMAR em
     *    homologação que a AGT o aceita); com `agt.nd_codigo_nao_sujeicao` segue como não sujeita
     *    (taxType NS + taxExemptionCode).
     *  - Linha a 0% (isenta ou não sujeita): tem de ter motivo de isenção; segue como IVA/ISE ou NS,
     *    sempre com taxExemptionCode (Anexo 6.4).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function impostos(Fatura|NotaCreditoDebito $documento, object $linha, bool $debitoSemImposto): array
    {
        $taxa = (string) $linha->taxa_iva;

        if ($debitoSemImposto) {
            if (Dinheiro::centimos($linha->valor_iva) !== 0 || Dinheiro::centimos($linha->taxa_iva) !== 0) {
                throw AgtException::configuracaoInvalida("a nota de débito {$documento->numero_documento} liquida IVA, o que não é permitido.");
            }

            $codigo = strtoupper(trim((string) config('faturacao.agt.nd_codigo_nao_sujeicao')));

            return $codigo === '' ? [] : [$this->impostoIsento('NS', $this->codigoNaoSujeicaoDebito($codigo))];
        }

        if ((float) $taxa == 0.0) {
            [$tipo, $codigo] = $this->motivoDeIsencao((string) ($linha->motivo_isencao ?? ''), (string) $documento->numero_documento);

            return [$this->impostoIsento($tipo, $codigo)];
        }

        return [[
            'taxType' => 'IVA',
            'taxCountryRegion' => 'AO',
            'taxCode' => $this->codigoImposto($taxa),
            'taxPercentage' => NumeroJson::deDecimal($taxa),
            'taxContribution' => NumeroJson::deCentimos(Dinheiro::centimos($linha->valor_iva)),
        ]];
    }

    /** @return array<string, mixed> */
    protected function impostoIsento(string $tipo, string $codigo): array
    {
        $imposto = ['taxType' => $tipo === 'NS' ? 'NS' : 'IVA', 'taxCountryRegion' => 'AO'];

        if ($tipo !== 'NS') {
            $imposto['taxCode'] = 'ISE';
        }

        return $imposto + [
            'taxPercentage' => NumeroJson::deCentimos(0),
            'taxContribution' => NumeroJson::deCentimos(0),
            'taxExemptionCode' => $codigo,
        ];
    }

    /** @return array{0: string, 1: string} [tipo (ISE|NS), código do motivo] */
    protected function motivoDeIsencao(string $motivo, string $numeroDocumento): array
    {
        $this->garantirTabelaDeIsencaoConfirmada();

        $codigo = strtoupper(trim($motivo));
        $entrada = ((array) config('faturacao.motivos_isencao', []))[$codigo] ?? null;

        if ($codigo === '' || ! is_array($entrada)) {
            throw AgtException::configuracaoInvalida("a linha isenta do documento {$numeroDocumento} não tem um motivo de isenção válido (config faturacao.motivos_isencao).");
        }

        return [($entrada['tipo'] ?? 'ISE') === 'NS' ? 'NS' : 'ISE', $codigo];
    }

    protected function codigoNaoSujeicaoDebito(string $codigo): string
    {
        $this->garantirTabelaDeIsencaoConfirmada();

        $entrada = ((array) config('faturacao.motivos_isencao', []))[$codigo] ?? null;

        if (! is_array($entrada) || ($entrada['tipo'] ?? null) !== 'NS') {
            throw AgtException::configuracaoInvalida('FATURACAO_AGT_ND_CODIGO_NS tem de ser um código de não sujeição (tipo NS) da tabela faturacao.motivos_isencao (Anexo 6.4).');
        }

        return $codigo;
    }

    /** Nada de códigos por confirmar a caminho da AGT. */
    protected function garantirTabelaDeIsencaoConfirmada(): void
    {
        if (! config('faturacao.motivos_isencao_confirmados')) {
            throw AgtException::configuracaoInvalida('a tabela de motivos de isenção ainda não foi confirmada contra o Anexo 6.4 (FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS=true).');
        }
    }

    protected function codigoImposto(string $taxa): string
    {
        $mapa = (array) config('faturacao.agt.codigos_taxa', []);
        $chave = number_format((float) $taxa, 2, '.', '');

        return $mapa[$chave] ?? throw AgtException::configuracaoInvalida("a taxa de IVA {$chave}% não tem código AGT (config faturacao.agt.codigos_taxa).");
    }

    protected function numero(mixed $valor): NumeroJson
    {
        return NumeroJson::deCentimos(Dinheiro::centimos($valor));
    }
}
