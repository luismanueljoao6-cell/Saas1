<?php

namespace Modules\Core\Notifications;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Jobs\ProcessNotificationJob;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Services\TenantManager;
use RuntimeException;

class NotificationDispatcher
{
    public function __construct(private readonly TenantManager $tenants) {}

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
                return $existing;
            }

            try {
                $notification = NotificationOutbox::create([
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
            } catch (QueryException $exception) {
                $existing = NotificationOutbox::withoutGlobalScopes()
                    ->where('empresa_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('channel', $channel)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                throw $exception;
            }

            return $notification;
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
