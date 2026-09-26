<?php

namespace Modules\Subscricoes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Models\Subscricao;
use Throwable;

/**
 * Corre uma vez por dia (ver README para o agendamento no Console Kernel /
 * bootstrap/app.php) e faz duas transições:
 *
 *  1. Subscrição ativa cujo termina_em já passou -> empresa suspensa
 *     (arranca o período de tolerância, ver TenantService::suspender()).
 *  2. Empresa suspensa cujo período de tolerância já terminou -> expirada
 *     (bloqueio total, ver VerificarSubscricaoAtiva no Core).
 *
 * Percorre TODAS as empresas — precisa por isso do bypass explícito do
 * TenantManager, tal como o PagamentoService.
 */
class VerificarSubscricoesExpiradasJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Injeção via handle(), não via construtor: um Job ShouldQueue é
     * serializado para ir para a fila, e TenantManager/TenantService não
     * precisam (nem devem) viajar nesse payload — o Laravel resolve-os de
     * novo, do container, no momento em que o Job corre.
     */
    public function handle(TenantManager $tenantManager, TenantService $tenantService): void
    {
        $tenantManager->semTenant(function () use ($tenantService) {
            $this->suspenderSubscricoesVencidas($tenantService);
            $this->expirarPeriodosDeTolerancia($tenantService);
        });
    }

    protected function suspenderSubscricoesVencidas(TenantService $tenantService): void
    {
        Subscricao::query()
            ->where('estado', 'ativa')
            ->where('termina_em', '<', now())
            ->with('empresa')
            ->chunkById(100, function ($subscricoes) use ($tenantService) {
                foreach ($subscricoes as $subscricao) {
                    try {
                        $subscricao->update(['estado' => 'expirada']);
                        $tenantService->suspender($subscricao->empresa);
                    } catch (Throwable $e) {
                        Log::error('Falha ao suspender subscrição vencida', [
                            'subscricao_id' => $subscricao->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    protected function expirarPeriodosDeTolerancia(TenantService $tenantService): void
    {
        Empresa::query()
            ->where('estado_subscricao', 'suspensa')
            ->where('periodo_tolerancia_ate', '<', now())
            ->chunkById(100, function ($empresas) use ($tenantService) {
                foreach ($empresas as $empresa) {
                    try {
                        $tenantService->expirar($empresa);
                    } catch (Throwable $e) {
                        Log::error('Falha ao expirar empresa após período de tolerância', [
                            'empresa_id' => $empresa->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
