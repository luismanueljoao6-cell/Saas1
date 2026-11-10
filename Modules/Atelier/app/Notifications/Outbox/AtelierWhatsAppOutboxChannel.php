<?php

namespace Modules\Atelier\Notifications\Outbox;

use InvalidArgumentException;
use Modules\Atelier\Services\Notificacoes\WhatsAppCanal;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;

final class AtelierWhatsAppOutboxChannel implements NotificationChannel
{
    public function __construct(private readonly WhatsAppCanal $canal) {}

    public function identifier(): string
    {
        return 'atelier.whatsapp';
    }

    public function send(NotificationOutbox $notification): NotificationSendResult
    {
        if (($recusa = app(ConsentimentoNoEnvio::class)->recusaSe($notification)) !== null) {
            return $recusa;
        }

        $message = $notification->payload['message'] ?? null;
        if (! is_string($message) || trim($message) === '') {
            throw new InvalidArgumentException('Payload.message é obrigatório para WhatsApp.');
        }

        $this->canal->enviar((string) $notification->recipient, $message);

        // O canal legado não expõe ID nem estado final de entrega.
        return NotificationSendResult::accepted($this->canal->identificador());
    }
}
