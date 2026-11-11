<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\NotificationOutbox;

class ListUnknownNotifications extends Command
{
    protected $signature = 'notifications:list-unknown
        {--empresa= : Filtrar por empresa}
        {--limit=50 : Máximo de linhas (1-500)}';

    protected $description = 'Lista as notificações da Outbox em resultado desconhecido que aguardam decisão.';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));

        $query = NotificationOutbox::withoutGlobalScopes()
            ->where('status', NotificationOutbox::STATUS_UNKNOWN_RESULT)
            ->orderBy('updated_at');

        if ($this->option('empresa')) {
            $query->where('empresa_id', (int) $this->option('empresa'));
        }

        $total = (clone $query)->count();
        $linhas = $query->limit($limit)->get();

        if ($linhas->isEmpty()) {
            $this->info('Nenhuma notificação em resultado desconhecido.');

            return self::SUCCESS;
        }

        // Sem destinatário nem payload, de propósito: dados pessoais não
        // devem aparecer no terminal nem em logs de sessões partilhadas.
        $this->table(
            ['ID', 'Empresa', 'Canal', 'Fornecedor', 'Tentativas', 'Há (min)', 'Motivo'],
            $linhas->map(fn ($n) => [
                $n->id,
                $n->empresa_id,
                $n->channel,
                $n->provider,
                $n->attempts,
                (int) abs($n->updated_at->diffInMinutes(now())),
                mb_substr((string) $n->last_error, 0, 60),
            ])->all(),
        );

        $this->line("A mostrar {$linhas->count()} de {$total}. Resolver: php artisan notifications:resolve-unknown ID --empresa=X --como=accepted|failed|requeue --motivo=\"...\"");

        return self::SUCCESS;
    }
}
