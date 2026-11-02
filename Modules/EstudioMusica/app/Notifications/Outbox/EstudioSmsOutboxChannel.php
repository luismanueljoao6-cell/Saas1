<?php

namespace Modules\EstudioMusica\Notifications\Outbox;

use InvalidArgumentException;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Modules\EstudioMusica\Support\TransferenciaInternacional;

final class EstudioSmsOutboxChannel implements NotificationChannel
{
    public function __construct(private readonly CanalSmsInterface $canal) {}

    public function identifier(): string
    {
        return 'estudio.sms';
    }

    public function send(NotificationOutbox $notification): NotificationSendResult
    {
        if (! TransferenciaInternacional::permitida($this->canal)) {
            return NotificationSendResult::rejected(
                $this->canal->identificador(),
                'Envio bloqueado: autorização para transferência internacional não configurada.',
                'apd_transfer_not_authorized',
            );
        }

        $message = $notification->payload['message'] ?? null;
        if (! is_string($message) || trim($message) === '') {
            throw new InvalidArgumentException('Payload.message é obrigatório para SMS.');
        }

        if (! $this->canal->enviar((string) $notification->recipient, $message)) {
            return NotificationSendResult::rejected(
                $this->canal->identificador(),
                'O canal SMS não confirmou a aceitação da mensagem.',
                'channel_rejected',
            );
        }

        return NotificationSendResult::accepted($this->canal->identificador());
    }
}
