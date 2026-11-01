<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
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
        $count = 0;

        NotificationOutbox::withoutGlobalScopes()
            ->where(function ($query) use ($now): void {
                $query->where(function ($pending) use ($now): void {
                    $pending->whereIn('status', [
                        NotificationOutbox::STATUS_PENDING,
                        NotificationOutbox::STATUS_RETRY_SCHEDULED,
                    ])->where(function ($due) use ($now): void {
                        $due->whereNull('available_at')->orWhere('available_at', '<=', $now);
                    });
                })->orWhere(function ($stale) use ($now): void {
                    $stale->where('status', NotificationOutbox::STATUS_PROCESSING)
                        ->where('locked_at', '<', $now->copy()->subMinutes(2));
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (NotificationOutbox $notification) use (&$count): void {
                if ($notification->status === NotificationOutbox::STATUS_PROCESSING) {
                    $notification->forceFill([
                        'status' => NotificationOutbox::STATUS_RETRY_SCHEDULED,
                        'locked_at' => null,
                        'lock_token' => null,
                        'available_at' => now(),
                    ])->save();
                }

                ProcessNotificationJob::dispatch($notification->id, (int) $notification->empresa_id);
                $count++;
            });

        $this->info("Notificações agendadas para processamento: {$count}");

        return self::SUCCESS;
    }
}
