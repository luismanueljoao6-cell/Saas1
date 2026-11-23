<?php

namespace Modules\Core\Contracts;

use Modules\Core\Models\NotificationOutbox;

/**
 * Envio de notificações através da Outbox do Core: idempotente por
 * (empresa, canal, chave) e isolado por empresa. Os módulos não escrevem
 * diretamente na Outbox nem chamam fornecedores externos.
 */
interface NotificationDispatcherInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        string $channel,
        string $recipient,
        array $payload,
        string $idempotencyKey,
        ?string $provider = null,
        int $maxAttempts = 5,
        ?int $empresaId = null,
    ): NotificationOutbox;
}
