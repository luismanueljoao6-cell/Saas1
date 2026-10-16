<?php

namespace Modules\Faturacao\Traits;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Support\Jws;
use Modules\Faturacao\Support\PayloadAssinaturaAgt;
use Modules\Faturacao\Support\QrCodeAgt;

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

    /**
     * Congela no documento o NIF, o nome e o país do cliente. Chamado pelo
     * serviço de assinatura no momento da emissão, antes de construir o objecto
     * assinado — daí em diante o documento não depende do registo do cliente.
     */
    public function congelarCliente(): void
    {
        $cliente = $this->clienteAtual();

        $this->cliente_nif = $cliente->nif;
        $this->cliente_nome = $cliente->nome;
        $this->cliente_pais = $cliente->pais ?: 'AO';
    }

    /** URL do QR Code AGT (consultar-fe), a partir do que foi assinado; null se ainda não emitido. */
    public function urlQrAgt(): ?string
    {
        $assinado = json_decode((string) Jws::payload((string) $this->hash), true);

        if (! is_array($assinado) || ! isset($assinado['taxRegistrationNumber'], $assinado['documentNo'])) {
            return null;
        }

        return QrCodeAgt::url((string) $assinado['taxRegistrationNumber'], (string) $assinado['documentNo']);
    }

    protected function clienteAtual(): Cliente
    {
        $cliente = Cliente::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->find($this->cliente_id);

        if (! $cliente) {
            throw AssinaturaFiscalException::dadosEmFalta('O cliente do documento não pertence a esta empresa.');
        }

        return $cliente;
    }

    protected function payloadAgtJson(int $impostoCentimos, int $liquidoCentimos, int $brutoCentimos): string
    {
        $empresa = Empresa::query()->find($this->empresa_id);

        if (! $empresa || trim((string) $empresa->nif) === '') {
            throw AssinaturaFiscalException::dadosEmFalta('A empresa emissora não tem NIF configurado.');
        }

        // Documentos emitidos usam os dados congelados; os anteriores a esta
        // funcionalidade (sem congelamento) usam os dados atuais do cliente.
        if ($this->cliente_nome !== null) {
            [$nif, $nome, $pais] = [$this->cliente_nif, $this->cliente_nome, $this->cliente_pais];
        } else {
            $cliente = $this->clienteAtual();
            [$nif, $nome, $pais] = [$cliente->nif, $cliente->nome, $cliente->pais];
        }

        return PayloadAssinaturaAgt::json(
            numeroDocumento: (string) $this->numero_documento,
            nifEmissor: (string) $empresa->nif,
            tipoDocumento: $this->tipoDocumentoFiscal(),
            dataDocumento: $this->data_emissao->format('Y-m-d'),
            nifCliente: $nif,
            paisCliente: $pais,
            nomeCliente: (string) $nome,
            impostoCentimos: $impostoCentimos,
            liquidoCentimos: $liquidoCentimos,
            brutoCentimos: $brutoCentimos,
        );
    }
}
