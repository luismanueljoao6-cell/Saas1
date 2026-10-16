<?php

namespace Modules\EstudioMusica\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\Reclamacao;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;

/**
 * Limitação da conservação (Lei n.º 22/11): pedidos de orçamento da
 * landing page são dados pessoais de quem NÃO chegou a ser cliente, por
 * isso não se guardam para sempre. Apaga os pedidos mais antigos que
 * `estudiomusica.retencao_solicitacoes_meses` (por omissão 12) e as
 * reclamações mais antigas que `retencao_reclamacoes_meses` (24), em todas
 * as empresas. Quem foi convertido em cliente já tem os dados na ficha.
 */
class PurgarSolicitacoesAntigasJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(TenantManager $tenantManager): void
    {
        $meses = max(1, (int) config('estudiomusica.retencao_solicitacoes_meses', 12));

        $mesesReclamacoes = max(1, (int) config('estudiomusica.retencao_reclamacoes_meses', 24));

        [$apagados, $reclamacoes] = $tenantManager->semTenant(fn () => [
            SolicitacaoOrcamento::query()->where('created_at', '<', now()->subMonths($meses))->delete(),
            Reclamacao::query()->where('created_at', '<', now()->subMonths($mesesReclamacoes))->delete(),
        ]);

        Log::info('EstudioMusica: dados antigos eliminados', [
            'pedidos_orcamento' => $apagados, 'meses_pedidos' => $meses,
            'reclamacoes' => $reclamacoes, 'meses_reclamacoes' => $mesesReclamacoes,
        ]);
    }
}
