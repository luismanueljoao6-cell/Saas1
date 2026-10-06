<?php

namespace Modules\Subscricoes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Services\SubscricaoService;
use Throwable;

/**
 * Rotina diária (ver SubscricoesServiceProvider para o agendamento; para
 * correr à mão: php artisan subscricoes:verificar). Passos, por ordem:
 *
 *  1. Referências de pagamento pendentes já fora de prazo -> 'expirado'.
 *  2. Subscrições 'pendente' sem nenhum pagamento vivo -> 'cancelada'.
 *  3. Trials terminados -> empresa em período de tolerância (suspensa).
 *  4. Subscrição ativa cujo termina_em passou -> empresa suspensa.
 *  5. Tolerância terminada -> empresa expirada (bloqueio total).
 *  6. Subscrições ativas a terminar em breve, com renovação ativa ->
 *     gera a referência de renovação e avisa a empresa.
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
     * serializado para ir para a fila, e os serviços não devem viajar nesse
     * payload — o Laravel resolve-os de novo, do container, ao correr.
     */
    public function handle(TenantManager $tenantManager, TenantService $tenantService): void
    {
        $tenantManager->semTenant(function () use ($tenantService) {
            $this->expirarPagamentosPendentes();
            $this->cancelarSubscricoesSemPagamento();
            $this->terminarTrials($tenantService);
            $this->suspenderSubscricoesVencidas($tenantService);
            $this->expirarPeriodosDeTolerancia($tenantService);
            $this->gerarRenovacoes();
        });
    }

    protected function expirarPagamentosPendentes(): void
    {
        Pagamento::query()
            ->where('estado', 'pendente')
            ->where('expira_em', '<', now())
            ->update(['estado' => 'expirado']);
    }

    protected function cancelarSubscricoesSemPagamento(): void
    {
        Subscricao::query()
            ->where('estado', 'pendente')
            ->with('pagamentos')
            ->chunkById(100, function ($subscricoes) {
                foreach ($subscricoes as $subscricao) {
                    $temPagamentoVivo = $subscricao->pagamentos
                        ->contains(fn ($p) => in_array($p->estado, ['pendente', 'confirmado'], true));

                    if (! $temPagamentoVivo) {
                        $subscricao->update(['estado' => 'cancelada', 'cancelada_em' => now()]);
                    }
                }
            });
    }

    protected function terminarTrials(TenantService $tenantService): void
    {
        // Só trials COM data de fim: empresas antigas sem data (trial sem
        // fim) não são tocadas.
        Empresa::query()
            ->where('estado_subscricao', 'trial')
            ->whereNotNull('subscricao_expira_em')
            ->where('subscricao_expira_em', '<', now())
            ->chunkById(100, function ($empresas) use ($tenantService) {
                foreach ($empresas as $empresa) {
                    try {
                        $tenantService->suspender($empresa);
                    } catch (Throwable $e) {
                        Log::error('Falha ao terminar o trial de uma empresa', [
                            'empresa_id' => $empresa->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
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

    protected function gerarRenovacoes(): void
    {
        $dias = (int) Config::get('subscricoes.aviso_renovacao_dias', 3);

        // Resolvido só aqui: se o gateway estiver mal configurado, falha
        // apenas este passo, não os cinco anteriores.
        $subscricaoService = app(SubscricaoService::class);

        Subscricao::query()
            ->where('estado', 'ativa')
            ->where('renovacao_automatica', true)
            ->where('termina_em', '>', now())
            ->where('termina_em', '<=', now()->addDays($dias))
            ->chunkById(100, function ($subscricoes) use ($subscricaoService) {
                foreach ($subscricoes as $subscricao) {
                    try {
                        $jaTemReferencia = Pagamento::query()
                            ->where('subscricao_id', $subscricao->id)
                            ->where('estado', 'pendente')
                            ->where('expira_em', '>', now())
                            ->exists();

                        if (! $jaTemReferencia) {
                            $subscricaoService->gerarRenovacao($subscricao);
                        }
                    } catch (Throwable $e) {
                        Log::error('Falha ao gerar a renovação de uma subscrição', [
                            'subscricao_id' => $subscricao->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
