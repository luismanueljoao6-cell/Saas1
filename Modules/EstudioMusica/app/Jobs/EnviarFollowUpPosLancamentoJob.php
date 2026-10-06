<?php

namespace Modules\EstudioMusica\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Services\CampanhaMarketingService;

/**
 * "Follow-up pós-lançamento: envio automático após 30 dias" (secção F).
 * Pensado para correr uma vez por dia — ver EstudioMusicaServiceProvider.
 */
class EnviarFollowUpPosLancamentoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager, CampanhaMarketingService $campanhas): void
    {
        $enviados = $campanhas->enviarFollowUpsPosLancamento($tenantManager);

        Log::info('EstudioMusica: follow-up pós-lançamento processado', ['enviados' => $enviados]);
    }
}
