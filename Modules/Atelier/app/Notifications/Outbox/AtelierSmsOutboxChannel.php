<?php

namespace Modules\Atelier\Notifications\Outbox;

use InvalidArgumentException;
use Modules\Atelier\Exceptions\NotificacaoResultadoIndeterminadoException;
use Modules\Atelier\Services\Notificacoes\SmsCanal;
use Modules\Atelier\Support\TransferenciaInternacional;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\Exceptions\ResultadoIndeterminadoException;

final class AtelierSmsOutboxChannel implements NotificationChannel
{
    public function __construct(private readonly SmsCanal $canal) {}

    public function identifier(): string
    {
        return 'atelier.sms';
    }

    public function send(NotificationOutbox $notification): NotificationSendResult
    {
        if (! TransferenciaInternacional::permitida()) {
            return NotificationSendResult::rejected(
                $this->canal->identificador(),
                'Envio bloqueado: falta configurar a autorização para transferência internacional de dados.',
                'apd_transfer_not_authorized',
            );
        }

        if (($recusa = app(ConsentimentoNoEnvio::class)->recusaSe($notification)) !== null) {
            return $recusa;
        }

        $message = $notification->payload['message'] ?? null;
        if (! is_string($message) || trim($message) === '') {
            throw new InvalidArgumentException('Payload.message é obrigatório para SMS.');
        }

        try {
            $this->canal->enviar((string) $notification->recipient, $message);
        } catch (NotificacaoResultadoIndeterminadoException $e) {
            // Nunca repetir automaticamente: a Outbox fica em «unknown_result» até haver decisão.
            throw new ResultadoIndeterminadoException($e->getMessage(), 0, $e);
        }

        return NotificationSendResult::accepted($this->canal->identificador());
    }
}
