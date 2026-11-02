<?php

namespace Modules\Core\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Jobs\ProcessNotificationJob;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Services\TenantManager;
use RuntimeException;

class NotificationDispatcher
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly NotificationChannelRegistry $channels,
    ) {}

    public function dispatch(
        string $channel,
        string $recipient,
        array $payload,
        string $idempotencyKey,
        ?string $provider = null,
        int $maxAttempts = 5,
        ?int $empresaId = null,
    ): NotificationOutbox {
        $tenantId = $this->tenants->id() ?? $empresaId;

        if (! $tenantId || $tenantId < 1) {
            throw new RuntimeException('É obrigatório identificar a empresa para registar uma notificação.');
        }

        if ($this->tenants->has() && $empresaId !== null && $empresaId !== $this->tenants->id()) {
            throw new RuntimeException('A empresa indicada não corresponde ao tenant atual.');
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('A chave de idempotência é inválida.');
        }

        if (trim($channel) === '' || trim($recipient) === '') {
            throw new InvalidArgumentException('O canal e o destinatário são obrigatórios.');
        }

        if (! $this->channels->has($channel)) {
            throw new RuntimeException(
                "O canal de notificação '{$channel}' ainda não tem um adaptador registado. "
                .'O envio foi bloqueado para evitar criar notificações que seriam tentadas sem fornecedor.'
            );
        }

        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new InvalidArgumentException('O limite de tentativas deve estar entre 1 e 20.');
        }

        // firstOrCreate usa a chave única da base de dados como árbitro
        // em concorrência e recupera a linha criada por outro pedido.
        $query = NotificationOutbox::withoutGlobalScopes();
        $notification = $query->firstOrCreate(
            [
                'empresa_id' => $tenantId,
                'idempotency_key' => $idempotencyKey,
                'channel' => $channel,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'provider' => $provider,
                'recipient' => $recipient,
                'payload' => $payload,
                'status' => NotificationOutbox::STATUS_PENDING,
                'max_attempts' => $maxAttempts,
                'available_at' => now(),
            ],
        );

        // A mesma chave nunca pode representar conteúdo ou destino diferentes.
        if (
            $notification->recipient !== $recipient
            || $notification->payload !== $payload
            || $notification->provider !== $provider
        ) {
            throw new RuntimeException(
                'A chave de idempotência já foi utilizada com dados diferentes para este canal.'
            );
        }

        if (in_array($notification->status, [
            NotificationOutbox::STATUS_PENDING,
            NotificationOutbox::STATUS_RETRY_SCHEDULED,
        ], true)) {
            ProcessNotificationJob::dispatch($notification->id, $tenantId)->afterCommit();
        }

        return $notification;
    }
}
