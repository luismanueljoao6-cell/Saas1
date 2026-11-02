<?php

namespace Modules\Core\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\NotificationAttempt;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Events\NotificationStateChanged;
use Modules\Core\Services\TenantManager;
use Throwable;

class NotificationProcessor
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly NotificationChannelRegistry $channels,
    ) {}

    public function process(int $notificationId, int $empresaId): void
    {
        // O processador também pode ser executado pelo driver "sync", no
        // próprio pedido HTTP. Nesse caso, não podemos deixar o tenant do
        // job substituir o tenant original do pedido.
        $tenantAnterior = $this->tenants->id();
        $this->tenants->set($empresaId);

        try {
            $this->processWithinTenant($notificationId, $empresaId);
        } finally {
            if ($tenantAnterior === null) {
                $this->tenants->clear();
            } else {
                $this->tenants->set($tenantAnterior);
            }
        }
    }

    private function processWithinTenant(int $notificationId, int $empresaId): void
    {
        $token = (string) Str::uuid();

        $claim = DB::transaction(function () use ($notificationId, $empresaId, $token): ?array {
            $notification = NotificationOutbox::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereKey($notificationId)
                ->lockForUpdate()
                ->first();

            if (! $notification || $notification->isTerminal()) {
                return null;
            }

            if ($notification->available_at?->isFuture()) {
                return null;
            }

            if ($notification->status === NotificationOutbox::STATUS_PROCESSING
                && $notification->locked_at?->gt(now()->subMinutes(2))) {
                return null;
            }

            if ($notification->attempts >= $notification->max_attempts) {
                $notification->forceFill([
                    'status' => NotificationOutbox::STATUS_DEAD_LETTER,
                    'failed_at' => now(),
                    'locked_at' => null,
                    'lock_token' => null,
                ])->save();

                NotificationStateChanged::dispatch(
                    (int) $notification->id,
                    $empresaId,
                    NotificationOutbox::STATUS_DEAD_LETTER,
                );

                return null;
            }

            $attemptNumber = $notification->attempts + 1;
            $notification->forceFill([
                'status' => NotificationOutbox::STATUS_PROCESSING,
                'attempts' => $attemptNumber,
                'locked_at' => now(),
                'lock_token' => $token,
            ])->save();

            $attempt = NotificationAttempt::create([
                'empresa_id' => $empresaId,
                'notification_outbox_id' => $notification->id,
                'attempt_number' => $attemptNumber,
                'status' => 'processing',
                'provider' => $notification->provider,
                'started_at' => now(),
            ]);

            return [
                'notification' => $notification->fresh(),
                'attempt_id' => $attempt->id,
                'lock_token' => $token,
            ];
        });

        if (! $claim) {
            return;
        }

        $notification = $claim['notification'];

        try {
            $channel = $this->channels->get($notification->channel);
            $result = $channel->send($notification);
        } catch (Throwable $exception) {
            $this->recordFailure($claim, $empresaId, $exception->getMessage());

            return;
        }

        DB::transaction(function () use ($claim, $empresaId, $result): void {
            $notification = NotificationOutbox::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereKey($claim['notification']->id)
                ->where('lock_token', $claim['lock_token'])
                ->lockForUpdate()
                ->first();

            if (! $notification) {
                return;
            }

            $attempt = NotificationAttempt::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereKey($claim['attempt_id'])
                ->first();

            if ($result->accepted) {
                $notification->forceFill([
                    'status' => NotificationOutbox::STATUS_ACCEPTED,
                    'provider' => $result->provider ?? $notification->provider,
                    'provider_message_id' => $result->providerMessageId,
                    'provider_response' => $result->metadata,
                    'accepted_at' => now(),
                    'locked_at' => null,
                    'lock_token' => null,
                    'last_error' => null,
                ])->save();

                $attempt?->forceFill([
                    'status' => 'accepted',
                    'provider' => $result->provider,
                    'provider_message_id' => $result->providerMessageId,
                    'response_code' => $result->responseCode,
                    'metadata' => $result->metadata,
                    'finished_at' => now(),
                ])->save();

                NotificationStateChanged::dispatch(
                    (int) $notification->id,
                    $empresaId,
                    NotificationOutbox::STATUS_ACCEPTED,
                );

                return;
            }

            $this->scheduleRetry($notification, $attempt, $result->errorMessage ?? 'Fornecedor recusou a notificação.', $result->errorCode, $result->responseCode, $result->metadata);

            $notification->refresh();
            if (in_array($notification->status, [NotificationOutbox::STATUS_FAILED, NotificationOutbox::STATUS_DEAD_LETTER], true)) {
                NotificationStateChanged::dispatch((int) $notification->id, $empresaId, $notification->status);
            }
        });
    }

    private function recordFailure(array $claim, int $empresaId, string $message): void
    {
        DB::transaction(function () use ($claim, $empresaId, $message): void {
            $notification = NotificationOutbox::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereKey($claim['notification']->id)
                ->where('lock_token', $claim['lock_token'])
                ->lockForUpdate()
                ->first();

            if (! $notification) {
                return;
            }

            $attempt = NotificationAttempt::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereKey($claim['attempt_id'])
                ->first();

            $this->scheduleRetry($notification, $attempt, $message);

            $notification->refresh();
            if (in_array($notification->status, [NotificationOutbox::STATUS_FAILED, NotificationOutbox::STATUS_DEAD_LETTER], true)) {
                NotificationStateChanged::dispatch((int) $notification->id, $empresaId, $notification->status);
            }
        });
    }

    private function scheduleRetry(
        NotificationOutbox $notification,
        ?NotificationAttempt $attempt,
        string $message,
        ?string $errorCode = null,
        ?int $responseCode = null,
        array $metadata = [],
    ): void {
        // Bloqueios legais ou de configuração não são resolvidos por retry.
        $permanent = $errorCode === 'apd_transfer_not_authorized';
        $terminal = $permanent || $notification->attempts >= $notification->max_attempts;
        $delay = min(3600, 30 * (2 ** max(0, $notification->attempts - 1)));

        $notification->forceFill([
            'status' => $permanent
                ? NotificationOutbox::STATUS_FAILED
                : ($terminal ? NotificationOutbox::STATUS_DEAD_LETTER : NotificationOutbox::STATUS_RETRY_SCHEDULED),
            'available_at' => $terminal ? $notification->available_at : now()->addSeconds($delay),
            'failed_at' => $terminal ? now() : null,
            'locked_at' => null,
            'lock_token' => null,
            'last_error' => mb_substr($message, 0, 5000),
        ])->save();

        $attempt?->forceFill([
            'status' => 'failed',
            'error_code' => $errorCode,
            'response_code' => $responseCode,
            'error_message' => mb_substr($message, 0, 5000),
            'metadata' => $metadata,
            'finished_at' => now(),
        ])->save();

        if (! $terminal) {
            ProcessNotificationJob::dispatch($notification->id, (int) $notification->empresa_id)
                ->delay(now()->addSeconds($delay))
                ->afterCommit();
        }
    }
}
