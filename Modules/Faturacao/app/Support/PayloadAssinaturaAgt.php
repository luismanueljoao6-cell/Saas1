<?php

namespace Modules\Faturacao\Support;

/**
 * Objecto assinado em jwsDocumentSignature, segundo a especificação da API de
 * Facturação Electrónica da AGT (serviço "Registar Factura"): documentNo,
 * taxRegistrationNumber, documentType, documentDate, customerTaxID,
 * customerCountry, companyName e documentTotals {taxPayable, netTotal,
 * grossTotal}. Assina-se o objecto inteiro, em JSON canónico.
 */
final class PayloadAssinaturaAgt
{
    /** NIF genérico previsto pela AGT para compradores domésticos sem identificação. */
    public const NIF_CONSUMIDOR_FINAL = '999999999';

    /**
     * @param  int  $impostoCentimos  taxPayable
     * @param  int  $liquidoCentimos  netTotal
     * @param  int  $brutoCentimos  grossTotal
     */
    public static function json(
        string $numeroDocumento,
        string $nifEmissor,
        string $tipoDocumento,
        string $dataDocumento,
        ?string $nifCliente,
        ?string $paisCliente,
        string $nomeCliente,
        int $impostoCentimos,
        int $liquidoCentimos,
        int $brutoCentimos,
    ): string {
        $nif = trim((string) $nifCliente);
        $pais = strtoupper(trim((string) $paisCliente)) ?: 'AO';

        return Jws::canonico([
            'documentNo' => $numeroDocumento,
            'taxRegistrationNumber' => $nifEmissor,
            'documentType' => $tipoDocumento,
            'documentDate' => $dataDocumento,
            'customerTaxID' => $nif !== '' ? $nif : self::NIF_CONSUMIDOR_FINAL,
            'customerCountry' => $pais,
            'companyName' => trim($nomeCliente) !== '' ? trim($nomeCliente) : 'Consumidor final',
            'documentTotals' => [
                'taxPayable' => NumeroJson::deCentimos($impostoCentimos),
                'netTotal' => NumeroJson::deCentimos($liquidoCentimos),
                'grossTotal' => NumeroJson::deCentimos($brutoCentimos),
            ],
        ]);
    }
}
