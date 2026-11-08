<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Jobs\ProcessNotificationJob;
use Modules\Core\Models\NotificationAttempt;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\NotificationProcessor;

class RecoverNotificationOutbox extends Command
{
    protected $signature = 'notifications:recover {--limit=500}';

    protected $description = 'Reagenda notificações pendentes ou abandonadas na Outbox.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $now = now();
        $staleBefore = $now->copy()->subSeconds(NotificationProcessor::LEASE_SEGUNDOS);
        $count = 0;
        $abandonadas = 0;

        DB::transaction(function () use ($limit, $now, $staleBefore, &$count, &$abandonadas): void {
            $notifications = NotificationOutbox::withoutGlobalScopes()
                ->where(function ($query) use ($now, $staleBefore): void {
                    $query->where(function ($pending) use ($now): void {
                        $pending->whereIn('status', [
                            NotificationOutbox::STATUS_PENDING,
                            NotificationOutbox::STATUS_RETRY_SCHEDULED,
                        ])->where(function ($due) use ($now): void {
                            $due->whereNull('available_at')->orWhere('available_at', '<=', $now);
                        });
                    })->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->where('status', NotificationOutbox::STATUS_PROCESSING)
                            ->whereNotNull('locked_at')
                            ->where('locked_at', '<', $staleBefore);
                    });
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($notifications as $notification) {
                // A linha permanece bloqueada até a alteração e o registo do
                // dispatch afterCommit, evitando que dois recuperadores limpem
                // simultaneamente o mesmo lease abandonado.
                if ($notification->status === NotificationOutbox::STATUS_PROCESSING) {
                    NotificationAttempt::assinalarAbandonadas((int) $notification->empresa_id, (int) $notification->id);
                    $abandonadas++;

                    $notification->forceFill([
                        'status' => NotificationOutbox::STATUS_RETRY_SCHEDULED,
                        'locked_at' => null,
                        'lock_token' => null,
                        'available_at' => $now,
                    ])->save();
                }

                ProcessNotificationJob::dispatch($notification->id, (int) $notification->empresa_id)
                    ->afterCommit();

                $count++;
            }
        });

        // Monitorização mínima, para um alerta poder apoiar-se nestas linhas de log.
        if ($abandonadas > 0) {
            Log::warning('Outbox: leases abandonados recuperados; o resultado das tentativas perdidas é desconhecido e a mensagem pode ser reenviada.', [
                'abandonadas' => $abandonadas,
            ]);
        }

        $deadLetters = NotificationOutbox::withoutGlobalScopes()
            ->where('status', NotificationOutbox::STATUS_DEAD_LETTER)
            ->where('failed_at', '>=', $now->copy()->subDay())
            ->count();

        if ($deadLetters > 0) {
            Log::warning('Outbox: notificações em dead letter nas últimas 24 horas precisam de atenção.', [
                'dead_letter_24h' => $deadLetters,
            ]);
        }

        $this->info("Notificações agendadas para processamento: {$count} (abandonadas recuperadas: {$abandonadas}; em dead letter nas últimas 24 h: {$deadLetters})");

        return self::SUCCESS;
    }
}
