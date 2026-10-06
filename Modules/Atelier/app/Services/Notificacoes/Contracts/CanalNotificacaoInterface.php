<?php

namespace Modules\Atelier\Services\Notificacoes\Contracts;

/**
 * Mesma abstração deliberada de GatewayPagamentoInterface (módulo
 * Subscricoes): o resto do módulo nunca fala diretamente com a API do
 * WhatsApp Business ou de um gateway de SMS — só com isto. Trocar de
 * fornecedor, ou acrescentar um novo canal, resume-se a implementar esta
 * interface e registá-la em NotificadorClienteService.
 */
interface CanalNotificacaoInterface
{
    /** Identificador curto (ex.: 'whatsapp', 'sms'), usado em logs e nas colunas `canal`. */
    public function identificador(): string;

    /**
     * Envia uma mensagem de texto simples para o destino indicado (número
     * de telefone em formato internacional, tipicamente).
     *
     * @throws \Modules\Atelier\Exceptions\NotificacaoException
     */
    public function enviar(string $destino, string $mensagem): void;
}
