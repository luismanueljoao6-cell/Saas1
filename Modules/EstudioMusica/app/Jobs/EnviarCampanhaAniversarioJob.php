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
 * "Mensagens de Aniversário do Artista com Cupom de Desconto" (secção F).
 * Pensado para correr uma vez por dia — ver EstudioMusicaServiceProvider.
 */
class EnviarCampanhaAniversarioJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager, CampanhaMarketingService $campanhas): void
    {
        $enviados = $campanhas->enviarAniversarios($tenantManager);

        Log::info('EstudioMusica: campanha de aniversário processada', ['enviados' => $enviados]);
    }
}
