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
