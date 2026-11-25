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
 * Único gatilho de CRM verdadeiramente automático (requisito D.1: mensagens
 * automáticas em datas comemorativas) — corre uma vez por dia.
 *
 * A descoberta percorre apenas os IDs das empresas. O CRM, as relações dos
 * clientes e o envio são executados dentro do contexto da empresa respetiva,
 * sem manter um bypass global durante o processamento de negócio.
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
        Empresa::query()
            ->select('id')
            ->chunkById(100, function ($referencias) use ($tenantManager, $crmService, $notificador, $linkService): void {
                foreach ($referencias as $referencia) {
                    $empresaId = (int) $referencia->id;

                    if ($empresaId < 1) {
                        continue;
                    }

                    $tenantManager->comEmpresa($empresaId, function () use ($empresaId, $crmService, $notificador, $linkService): void {
                        $empresa = Empresa::query()->find($empresaId);

                        if (! $empresa || ! $empresa->temAcesso() || ! $empresa->temServico('atelier')) {
                            return;
                        }

                        try {
                            foreach ($crmService->aniversariantesHoje($empresaId) as $cliente) {
                                if ((int) $cliente->empresa_id !== $empresaId) {
                                    continue;
                                }

                                $chave = "atelier:aniversario:{$empresaId}:{$cliente->id}:".now()->format('Y-m-d');

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
                                'empresa_id' => $empresaId,
                                'erro' => $e->getMessage(),
                            ]);
                        }
                    });
                }
            });
    }
}
