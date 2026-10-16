<?php

namespace Modules\Subscricoes\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Subscricoes\Models\WebhookEvento;

/**
 * Verifica as dependências operacionais do módulo: scheduler, fila e caixa
 * de entrada de webhooks. Corre de hora a hora; código de saída 1 = problema.
 *
 *   php artisan subscricoes:saude
 */
class SaudeSubscricoesCommand extends Command
{
    protected $signature = 'subscricoes:saude';

    protected $description = 'Verifica scheduler, fila e webhooks pendentes do módulo de subscrições';

    public function handle(): int
    {
        $problemas = [];

        $ultima = Cache::get('subscricoes:ultima_verificacao');

        if (! is_array($ultima) || empty($ultima['em'])) {
            $problemas[] = 'A rotina diária de subscrições ainda não correu (scheduler parado?).';
        } else {
            if (Carbon::parse($ultima['em'])->lt(now()->subHours(26))) {
                $problemas[] = 'A rotina diária não corre há mais de 26 horas (última: '.$ultima['em'].').';
            }

            if (($ultima['falhas'] ?? 0) > 0) {
                $problemas[] = "A última rotina diária terminou com {$ultima['falhas']} falha(s) — ver logs.";
            }
        }

        $pendentes = WebhookEvento::query()
            ->whereIn('estado', [WebhookEvento::RECEBIDO, WebhookEvento::FALHADO])
            ->where('created_at', '<', now()->subMinutes(15))
            ->count();

        if ($pendentes > 0) {
            $problemas[] = "{$pendentes} webhook(s) guardado(s) e por processar há mais de 15 min (fila parada?).";
        }

        $revisao = WebhookEvento::query()
            ->whereIn('estado', [WebhookEvento::REJEITADO, WebhookEvento::ILEGIVEL])
            ->count();

        if ($revisao > 0) {
            $problemas[] = "{$revisao} webhook(s) a aguardar revisão manual (php artisan subscricoes:reconciliar).";
        }

        if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
            $atrasados = DB::table('jobs')
                ->where('available_at', '<', now()->subMinutes(10)->getTimestamp())
                ->count();

            if ($atrasados > 0) {
                $problemas[] = "{$atrasados} job(s) na fila há mais de 10 min — o queue:work está ativo?";
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $falhados = DB::table('failed_jobs')->where('failed_at', '>', now()->subDay())->count();

            if ($falhados > 0) {
                $problemas[] = "{$falhados} job(s) falhado(s) nas últimas 24 h (php artisan queue:failed).";
            }
        }

        if ($problemas === []) {
            $this->info('Subscrições: tudo operacional.');

            return self::SUCCESS;
        }

        foreach ($problemas as $problema) {
            $this->error($problema);
        }

        Log::critical('Saúde do módulo Subscrições: problemas detetados', ['problemas' => $problemas]);

        return self::FAILURE;
    }
}
