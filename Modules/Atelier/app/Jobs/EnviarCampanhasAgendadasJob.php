<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Atelier\Models\Campanha;
use Modules\Core\Services\TenantManager;

/**
 * Faz o polling de campanhas com agendada_para vencida — o mesmo motivo já
 * documentado em config/config.php para os lembretes de prova: um Job
 * despachado com ->delay() não sobrevive a um restart da fila, polling
 * agendado sim. Corre de hora a hora; o trabalho de envio em si fica em
 * DispararCampanhaJob.
 */
class EnviarCampanhasAgendadasJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager): void
    {
        $tenantManager->semTenant(function () {
            Campanha::query()
                ->where('estado', 'agendada')
                ->where('agendada_para', '<=', now())
                ->chunkById(100, function ($campanhas) {
                    foreach ($campanhas as $campanha) {
                        DispararCampanhaJob::dispatch($campanha->id);
                    }
                });
        });
    }
}
