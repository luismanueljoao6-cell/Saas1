<?php

namespace Modules\Faturacao\Traits;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Support\PayloadAssinaturaAgt;

/**
 * Constrói o objecto JSON canónico assinado em jwsDocumentSignature para os
 * documentos fiscais (Fatura, NotaCreditoDebito, Recibo). Lê empresa e cliente
 * sem depender do tenant ambiente (emissão corre em jobs, comandos e testes).
 */
trait AssinavelAgt
{
    /**
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
