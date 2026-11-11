<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Events\NotificationStateChanged;
use Modules\Core\Jobs\ProcessNotificationJob;
use Modules\Core\Models\NotificationAttempt;
use Modules\Core\Models\NotificationOutbox;
use Throwable;

class ResolveUnknownNotification extends Command
{
    protected $signature = 'notifications:resolve-unknown
        {id : ID da notificação em unknown_result}
        {--empresa= : ID da empresa proprietária (obrigatório)}
        {--como= : accepted (o fornecedor recebeu) | failed (o fornecedor não recebeu) | requeue (reenviar)}
        {--motivo= : Justificação da decisão (obrigatória, fica registada)}
        {--provider-message-id= : (accepted) identificador confirmado no fornecedor}
        {--confirmo-duplicado : (requeue) aceita o risco de a mensagem ser entregue duas vezes}';

    protected $description = 'Resolve, por decisão explícita, uma notificação da Outbox em resultado desconhecido.';

    public function handle(): int
    {
        $empresaId = (int) $this->option('empresa');
        $como = (string) $this->option('como');
        $motivo = trim((string) $this->option('motivo'));
        $notificacaoId = (int) $this->argument('id');

        if ($empresaId <= 0) {
            return $this->recusar('Indique a empresa proprietária com --empresa=ID.');
        }

        if (! in_array($como, ['accepted', 'failed', 'requeue'], true)) {
            return $this->recusar('Indique --como=accepted, --como=failed ou --como=requeue.');
        }

        if ($motivo === '') {
            return $this->recusar('Indique o motivo da decisão com --motivo="...".');
        }

        if ($como === 'requeue' && ! $this->option('confirmo-duplicado')) {
            return $this->recusar('Reenviar pode duplicar a mensagem. Confirme com --confirmo-duplicado.');
        }

        $erro = DB::transaction(function () use ($empresaId, $notificacaoId, $como, $motivo): ?string {
            $notification = NotificationOutbox::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereKey($notificacaoId)
                ->lockForUpdate()
                ->first();

            if (! $notification) {
                return 'Notificação não encontrada para esta empresa.';
            }

            if ($notification->status !== NotificationOutbox::STATUS_UNKNOWN_RESULT) {
                return "A notificação está em «{$notification->status}»: só se resolve o estado unknown_result.";
            }

            $nota = 'Resolvido manualmente ('.$como.'): '.mb_substr($motivo, 0, 1000);

            if ($como === 'accepted') {
                $notification->forceFill([
                    'status' => NotificationOutbox::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                    'provider_message_id' => $this->option('provider-message-id') ?: $notification->provider_message_id,
                    'failed_at' => null,
                    'last_error' => $nota,
                ])->save();
                $this->emitirEstado($notificacaoId, $empresaId, NotificationOutbox::STATUS_ACCEPTED);
            } elseif ($como === 'failed') {
                $notification->forceFill([
                    'status' => NotificationOutbox::STATUS_FAILED,
                    'failed_at' => now(),
                    'last_error' => $nota,
                ])->save();
                $this->emitirEstado($notificacaoId, $empresaId, NotificationOutbox::STATUS_FAILED);
            } else {
                // Decisão humana de aceitar o risco de duplicado: garante pelo menos
                // mais uma tentativa, mesmo que o limite já tivesse sido atingido.
                $notification->forceFill([
                    'status' => NotificationOutbox::STATUS_PENDING,
                    'available_at' => now(),
                    'failed_at' => null,
                    'max_attempts' => max((int) $notification->max_attempts, (int) $notification->attempts + 1),
                    'last_error' => $nota,
                ])->save();

                DB::afterCommit(function () use ($notificacaoId, $empresaId): void {
                    ProcessNotificationJob::dispatch($notificacaoId, $empresaId)->afterCommit();
                });
            }

            $ultima = NotificationAttempt::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->where('notification_outbox_id', $notificacaoId)
                ->orderByDesc('attempt_number')
                ->first();

            if ($ultima) {
                $metadata = $ultima->metadata ?? [];
                $metadata['resolucao_manual'] = [
                    'decisao' => $como,
                    'motivo' => mb_substr($motivo, 0, 1000),
                    'em' => now()->toIso8601String(),
                ];
                $ultima->forceFill(['metadata' => $metadata])->save();
            }

            return null;
        });

        if ($erro !== null) {
            return $this->recusar($erro);
        }

        Log::warning('Outbox: notificação em resultado desconhecido resolvida manualmente.', [
            'notificacao_id' => $notificacaoId,
            'empresa_id' => $empresaId,
            'decisao' => $como,
        ]);

        $this->info("Notificação {$notificacaoId} resolvida: {$como}.");

        return self::SUCCESS;
    }

    private function recusar(string $mensagem): int
    {
        $this->error($mensagem);

        return self::FAILURE;
    }

    /** Só depois do commit e sem nunca poder desfazer a decisão (como no processador). */
    private function emitirEstado(int $notificacaoId, int $empresaId, string $status): void
    {
        DB::afterCommit(function () use ($notificacaoId, $empresaId, $status): void {
            try {
                NotificationStateChanged::dispatch($notificacaoId, $empresaId, $status);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
