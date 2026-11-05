<?php

namespace Modules\Atelier\Notifications\Outbox;

use InvalidArgumentException;
use Modules\Atelier\Services\Notificacoes\SmsCanal;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;

final class AtelierSmsOutboxChannel implements NotificationChannel
{
    public function __construct(private readonly SmsCanal $canal) {}

    public function identifier(): string
    {
        return 'atelier.sms';
    }

    public function send(NotificationOutbox $notification): NotificationSendResult
    {
        $message = $notification->payload['message'] ?? null;
        if (! is_string($message) || trim($message) === '') {
            throw new InvalidArgumentException('Payload.message é obrigatório para SMS.');
        }

        $this->canal->enviar((string) $notification->recipient, $message);

        return NotificationSendResult::accepted($this->canal->identificador());
    }
}
