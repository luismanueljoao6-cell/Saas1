<?php

namespace Modules\EstudioMusica\Notifications\Outbox;

use InvalidArgumentException;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Modules\EstudioMusica\Support\TransferenciaInternacional;

/**
 * Adaptador do canal legado do Estúdio para a Outbox partilhada do Core.
 * A política APD continua a ser aplicada antes de qualquer envio externo.
 */
final class EstudioWhatsAppOutboxChannel implements NotificationChannel
{
    public function __construct(private readonly CanalWhatsAppInterface $canal) {}

    public function identifier(): string
    {
        return 'estudio.whatsapp';
    }

    public function send(NotificationOutbox $notification): NotificationSendResult
    {
        if (! TransferenciaInternacional::permitida($this->canal)) {
            return NotificationSendResult::rejected(
                $this->canal->identificador(),
                'Envio bloqueado: falta configurar a autorização para transferência internacional de dados.',
                'apd_transfer_not_authorized',
            );
        }

        $message = $notification->payload['message'] ?? null;
        if (! is_string($message) || trim($message) === '') {
            throw new InvalidArgumentException('A notificação do Estúdio não contém payload.message válido.');
        }

        if (! $this->canal->enviar((string) $notification->recipient, $message)) {
            return NotificationSendResult::rejected(
                $this->canal->identificador(),
                'O canal WhatsApp não confirmou a aceitação da mensagem.',
                'channel_rejected',
            );
        }

        // O contrato legado devolve apenas bool; não permite afirmar entrega
        // nem obter o ID de mensagem do fornecedor.
        return NotificationSendResult::accepted($this->canal->identificador());
    }
}
