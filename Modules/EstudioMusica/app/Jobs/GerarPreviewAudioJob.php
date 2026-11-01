<?php

namespace Modules\EstudioMusica\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\EstudioMusica\Services\PreviewAudioService;

/**
 * Gera a pré-visualização (mp3 mono, baixa taxa de bits) de uma versão logo
 * a seguir ao upload, fora do pedido HTTP. Antes só nascia no primeiro "play"
 * do cliente: o pedido ficava a transcodificar com o ffmpeg (até 3 minutos) e
 * os vários pedidos Range do <audio> arrancavam vários ffmpeg em paralelo.
 *
 * Recebe só ids (um worker não tem tenant identificado) e trabalha sem o scope
 * de tenant, restringindo à empresa a que a versão pertence.
 */
class GerarPreviewAudioJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $versaoId, public int $empresaId) {}

    public function handle(TenantManager $tenantManager, PreviewAudioService $previews): void
    {
        $tenantManager->semTenant(function () use ($previews): void {
            $versao = VersaoAudio::query()
                ->where('empresa_id', $this->empresaId)
                ->find($this->versaoId);

            if ($versao !== null) {
                $previews->garantir($versao);
            }
        });
    }
}
