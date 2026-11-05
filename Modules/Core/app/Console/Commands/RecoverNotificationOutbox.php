<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Jobs\ProcessNotificationJob;
use Modules\Core\Models\NotificationOutbox;

class RecoverNotificationOutbox extends Command
{
    protected $signature = 'notifications:recover {--limit=500}';

    protected $description = 'Reagenda notificações pendentes ou abandonadas na Outbox.';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $now = now();
        $staleBefore = $now->copy()->subMinutes(2);
        $count = 0;

        DB::transaction(function () use ($limit, $now, $staleBefore, &$count): void {
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

        $this->info("Notificações agendadas para processamento: {$count}");

        return self::SUCCESS;
    }
}
