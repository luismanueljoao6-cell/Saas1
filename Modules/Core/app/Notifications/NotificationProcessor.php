<?php

namespace Modules\Core\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Events\NotificationStateChanged;
use Modules\Core\Jobs\ProcessNotificationJob;
use Modules\Core\Models\NotificationAttempt;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Exceptions\ResultadoIndeterminadoException;
use Modules\Core\Services\TenantManager;
use InvalidArgumentException;
use Throwable;

class NotificationProcessor
{
    /**
     * Duração do lease de uma notificação em processamento. Tem de ser MAIOR do
     * que o timeout do ProcessNotificationJob (e, portanto, do pedido ao
     * fornecedor): só depois de o worker ter sido interrompido é que outro pode
     * recuperar a notificação.
     */
    public const LEASE_SEGUNDOS = 120;

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

            // «accepted» não é terminal (ainda pode passar a entregue ou lida), mas o
            // fornecedor já tem a mensagem: um job repetido nunca a pode reenviar nem
            // convertê-la em dead letter.
            if (! $notification || $notification->isTerminal() || $notification->foiEntregueAoFornecedor()) {
                return null;
            }

            if ($notification->available_at?->isFuture()) {
                return null;
            }

            if ($notification->status === NotificationOutbox::STATUS_PROCESSING
                && $notification->locked_at?->gt(now()->subSeconds(self::LEASE_SEGUNDOS))) {
                return null;
            }

            if ($notification->status === NotificationOutbox::STATUS_PROCESSING) {
                // O lease expirou: o worker foi interrompido e não sabemos se o fornecedor
                // aceitou a mensagem. Reenviar poderia duplicá-la; fica em «resultado
                // desconhecido» até haver reconciliação ou decisão controlada.
                NotificationAttempt::assinalarAbandonadas($empresaId, (int) $notification->id);
                $this->marcarResultadoDesconhecido($notification, $empresaId);

                return null;
            }

            if ($notification->attempts >= $notification->max_attempts) {
                $notification->forceFill([
                    'status' => NotificationOutbox::STATUS_DEAD_LETTER,
                    'failed_at' => now(),
                    'locked_at' => null,
                    'lock_token' => null,
                ])->save();

                $this->emitirEstado((int) $notification->id, $empresaId, NotificationOutbox::STATUS_DEAD_LETTER);

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
        } catch (ResultadoIndeterminadoException $exception) {
            // O pedido pode ter chegado ao fornecedor: nunca se repete sozinho.
            $this->recordUnknownResult($claim, $empresaId, $exception);

            return;
        } catch (InvalidArgumentException $exception) {
            // Payload inválido não se corrige a repetir: falha definitiva.
            $this->recordFailure($claim, $empresaId, $exception->getMessage(), 'invalid_payload');

            return;
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

                $this->emitirEstado((int) $notification->id, $empresaId, NotificationOutbox::STATUS_ACCEPTED);

                return;
            }

            $this->scheduleRetry($notification, $attempt, $result->errorMessage ?? 'Fornecedor recusou a notificação.', $result->errorCode, $result->responseCode, $result->metadata);

            $notification->refresh();
            if (in_array($notification->status, [NotificationOutbox::STATUS_FAILED, NotificationOutbox::STATUS_DEAD_LETTER], true)) {
                $this->emitirEstado((int) $notification->id, $empresaId, $notification->status);
            }
        });
    }

    private function marcarResultadoDesconhecido(NotificationOutbox $notification, int $empresaId, ?string $motivo = null): void
    {
        $notification->forceFill([
            'status' => NotificationOutbox::STATUS_UNKNOWN_RESULT,
            'locked_at' => null,
            'lock_token' => null,
            'last_error' => $motivo === null
                ? 'Resultado desconhecido: o worker foi interrompido sem registar a resposta do fornecedor.'
                : 'Resultado desconhecido: '.mb_substr($motivo, 0, 4800),
        ])->save();

        $this->emitirEstado((int) $notification->id, $empresaId, NotificationOutbox::STATUS_UNKNOWN_RESULT);
    }

    /**
     * O canal declarou que não sabe se o fornecedor recebeu a mensagem. Fica em
     * «unknown_result», sem retry, até haver reconciliação ou decisão controlada
     * (php artisan notifications:resolve-unknown).
     */
    private function recordUnknownResult(array $claim, int $empresaId, ResultadoIndeterminadoException $exception): void
    {
        DB::transaction(function () use ($claim, $empresaId, $exception): void {
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

            $attempt?->forceFill([
                'status' => 'unknown',
                'error_code' => 'unknown_result',
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'finished_at' => now(),
            ])->save();

            $this->marcarResultadoDesconhecido($notification, $empresaId, $exception->getMessage());
        });
    }

    private function recordFailure(array $claim, int $empresaId, string $message, ?string $errorCode = null): void
    {
        DB::transaction(function () use ($claim, $empresaId, $message, $errorCode): void {
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

            $this->scheduleRetry($notification, $attempt, $message, $errorCode);

            $notification->refresh();
            if (in_array($notification->status, [NotificationOutbox::STATUS_FAILED, NotificationOutbox::STATUS_DEAD_LETTER], true)) {
                $this->emitirEstado((int) $notification->id, $empresaId, $notification->status);
            }
        });
    }

    /**
     * Avisa os outros módulos (ex.: campanhas do Atelier) de que o estado mudou.
     *
     * Só depois de a transação ser confirmada e sem nunca a poder desfazer: se um
     * listener falhar, o fornecedor já aceitou ou recusou a mensagem e reverter o
     * estado levaria o recuperador a reenviá-la. Os outros módulos reconciliam a
     * partir da própria Outbox.
     */
    private function emitirEstado(int $notificationId, int $empresaId, string $status): void
    {
        DB::afterCommit(function () use ($notificationId, $empresaId, $status): void {
            try {
                NotificationStateChanged::dispatch($notificationId, $empresaId, $status);
            } catch (Throwable $exception) {
                report($exception);
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
        // Credenciais, bloqueios legais/configuração e destinatários inválidos não
        // se resolvem por retry; limites do fornecedor esperam mais.
        $classe = NotificationRetryPolicy::classify($errorCode, $responseCode);
        $permanent = NotificationRetryPolicy::semRetry($classe);
        $terminal = $permanent || $notification->attempts >= $notification->max_attempts;
        $delay = NotificationRetryPolicy::atrasoSegundos($classe, (int) $notification->attempts, $metadata);

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

        if ($classe === NotificationRetryPolicy::BLOCKED) {
            // Sem destinatário nem payload: só o necessário para um alerta operacional.
            Log::critical('Outbox: envio bloqueado por credenciais ou configuração; é preciso intervenção, o retry não resolve.', [
                'notification_id' => $notification->id,
                'empresa_id' => $notification->empresa_id,
                'channel' => $notification->channel,
                'provider' => $notification->provider,
                'error_code' => $errorCode,
                'response_code' => $responseCode,
            ]);
        }

        if (! $terminal) {
            ProcessNotificationJob::dispatch($notification->id, (int) $notification->empresa_id)
                ->delay(now()->addSeconds($delay))
                ->afterCommit();
        }
    }
}
