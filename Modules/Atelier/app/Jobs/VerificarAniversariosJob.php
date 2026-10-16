<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Único gatilho de CRM verdadeiramente AUTOMÁTICO (requisito D.1: "Mensagens
 * automáticas em datas comemorativas") — corre uma vez por dia. As
 * promoções sazonais e a reativação de inativos são, de propósito,
 * campanhas manuais (ver Admin\CampanhaController) e não entram aqui.
 *
 * Só processa empresas com acesso ativo E com o serviço atelier contratado,
 * só envia a clientes com consentimento de marketing (CrmService) e nunca
 * envia duas vezes ao mesmo cliente no mesmo dia (um retry da fila ou uma
 * segunda execução não duplica a mensagem).
 */
class VerificarAniversariosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(
        TenantManager $tenantManager,
        CrmService $crmService,
        NotificadorClienteService $notificador,
        PortalLinkService $linkService,
    ): void {
        $tenantManager->semTenant(function () use ($crmService, $notificador, $linkService) {
            Empresa::query()->chunkById(100, function ($empresas) use ($crmService, $notificador, $linkService) {
                foreach ($empresas as $empresa) {
                    if (! $empresa->temAcesso() || ! $empresa->temServico('atelier')) {
                        continue;
                    }

                    try {
                        foreach ($crmService->aniversariantesHoje($empresa->id) as $cliente) {
                            $chave = "atelier:aniversario:{$empresa->id}:{$cliente->id}:".now()->format('Y-m-d');

                            // Cache::add é atómico: só quem o cria no dia envia.
                            if (! Cache::add($chave, 1, now()->addDays(2))) {
                                continue;
                            }

                            $notificador->notificar(
                                $cliente,
                                "Feliz Aniversário! — {$empresa->nome_comercial}",
                                "Hoje é um dia especial! A equipa do {$empresa->nome_comercial} deseja-lhe um feliz aniversário."
                                    ."\n\nPara deixar de receber estas mensagens: ".$linkService->gerarLinkCancelarMarketing($cliente),
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
