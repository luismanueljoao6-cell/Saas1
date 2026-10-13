<?php

namespace Modules\Faturacao\Traits;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Support\PayloadAssinaturaAgt;

/**
 * Constrói o objecto JSON canónico assinado em jwsDocumentSignature para os
 * documentos fiscais (Fatura, NotaCreditoDebito, Recibo). Lê empresa e cliente
 * sem depender do tenant ambiente (emissão corre em jobs, comandos e testes).
 */
trait AssinavelAgt
{
    protected function payloadAgtJson(int $impostoCentimos, int $liquidoCentimos, int $brutoCentimos): string
    {
        $empresa = Empresa::query()->find($this->empresa_id);
        $cliente = Cliente::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->find($this->cliente_id);

        if (! $empresa || trim((string) $empresa->nif) === '') {
            throw AssinaturaFiscalException::dadosEmFalta('A empresa emissora não tem NIF configurado.');
        }

        if (! $cliente) {
            throw AssinaturaFiscalException::dadosEmFalta('O cliente do documento não pertence a esta empresa.');
        }

        return PayloadAssinaturaAgt::json(
            numeroDocumento: (string) $this->numero_documento,
            nifEmissor: (string) $empresa->nif,
            tipoDocumento: $this->tipoDocumentoFiscal(),
            dataDocumento: $this->data_emissao->format('Y-m-d'),
            nifCliente: $cliente->nif,
            paisCliente: $cliente->pais,
            nomeCliente: (string) $cliente->nome,
            impostoCentimos: $impostoCentimos,
            liquidoCentimos: $liquidoCentimos,
            brutoCentimos: $brutoCentimos,
        );
    }
}
