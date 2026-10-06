<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Único gatilho de CRM verdadeiramente AUTOMÁTICO (requisito D.1: "Mensagens
 * automáticas em datas comemorativas") — corre uma vez por dia. As
 * promoções sazonais e a reativação de inativos são, de propósito,
 * campanhas manuais (ver Admin\CampanhaController) e não entram aqui.
 */
class VerificarAniversariosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager, CrmService $crmService, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($crmService, $notificador) {
            Empresa::query()->chunkById(100, function ($empresas) use ($crmService, $notificador) {
                foreach ($empresas as $empresa) {
                    try {
                        foreach ($crmService->aniversariantesHoje($empresa->id) as $cliente) {
                            $notificador->notificar(
                                $cliente,
                                "Feliz Aniversário! — {$empresa->nome_comercial}",
                                "Hoje é um dia especial! A equipa do {$empresa->nome_comercial} deseja-lhe um feliz aniversário.",
                            );
                        }
                    } catch (Throwable $e) {
                        Log::error('Atelier: falha ao processar aniversários da empresa', [
                            'empresa_id' => $empresa->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });
        });
    }
}
