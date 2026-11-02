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

        $notification = DB::transaction(function () use (
            $tenantId, $channel, $recipient, $payload, $idempotencyKey, $provider, $maxAttempts
        ): NotificationOutbox {
            $existing = NotificationOutbox::withoutGlobalScopes()
                ->where('empresa_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->where('channel', $channel)
                ->first();

            if ($existing) {
                // Uma chave idempotente identifica uma operação, não apenas
                // uma linha. Reutilizá-la com outro conteúdo poderia fazer o
                // chamador acreditar que uma mensagem diferente foi enviada.
                if (
                    $existing->recipient !== $recipient
                    || $existing->payload !== $payload
                    || $existing->provider !== $provider
                ) {
                    throw new RuntimeException(
                        'A chave de idempotência já foi utilizada com dados diferentes para este canal.'
                    );
                }

                return $existing;
            }

            return NotificationOutbox::create([
                'empresa_id' => $tenantId,
                'uuid' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'channel' => $channel,
                'provider' => $provider,
                'recipient' => $recipient,
                'payload' => $payload,
                'status' => NotificationOutbox::STATUS_PENDING,
                'max_attempts' => $maxAttempts,
                'available_at' => now(),
            ]);
        });

        if (in_array($notification->status, [
            NotificationOutbox::STATUS_PENDING,
            NotificationOutbox::STATUS_RETRY_SCHEDULED,
        ], true)) {
            ProcessNotificationJob::dispatch($notification->id, $tenantId)->afterCommit();
        }

        return $notification;
    }
}
