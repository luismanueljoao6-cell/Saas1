<?php

namespace Modules\\Atelier\\Jobs;

use Illuminate\\Bus\\Queueable;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Foundation\\Bus\\Dispatchable;
use Illuminate\\Queue\\InteractsWithQueue;
use Illuminate\\Queue\\SerializesModels;
use Illuminate\\Support\\Facades\\DB;

/**
 * Faz o polling de campanhas com agendada_para vencida.
 *
 * A descoberta é global, mas só lê os IDs necessários para despachar o job.
 * O processamento da campanha é sempre feito por DispararCampanhaJob, que
 * resolve a empresa proprietária e entra no respetivo contexto tenant.
 */
class EnviarCampanhasAgendadasJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        DB::table('atelier_campanhas')
            ->select('id')
            ->where('estado', 'agendada')
            ->where('agendada_para', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($campanhas): void {
                foreach ($campanhas as $campanha) {
                    DispararCampanhaJob::dispatch((int) $campanha->id);
                }
            });
    }
}
