<?php

namespace Modules\Subscricoes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Services\SubscricaoService;
use Throwable;

/**
 * Rotina diária (php artisan subscricoes:verificar). Passos:
 *  1. Referências pendentes fora de prazo -> 'expirado'.
 *  2. Subscrições 'pendente' sem pagamento vivo -> 'cancelada'.
 *  3. Trials terminados -> empresa suspensa (período de tolerância).
 *  4. Subscrição ativa vencida -> empresa suspensa.
 *  5. Tolerância terminada -> empresa expirada.
 *  6. Subscrições a terminar em breve -> referência de renovação.
 *
 * Cada transição RELÊ o registo com lock e revalida a condição: um pagamento
 * confirmado entretanto pelo webhook nunca é revertido por modelos antigos.
 * Ordem de locks (igual à do PagamentoService): Subscricao -> Empresa.
 * ShouldBeUnique: nunca há duas execuções em simultâneo.
 */
class VerificarSubscricoesExpiradasJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'subscricoes-verificar';
    }

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
        // UPDATE condicional: só afeta o que ainda está 'pendente' neste instante.
        Pagamento::query()
            ->where('estado', 'pendente')
            ->where('expira_em', '<', now())
            ->update(['estado' => 'expirado']);
    }

    protected function cancelarSubscricoesSemPagamento(): void
    {
        Subscricao::query()
            ->where('estado', 'pendente')
            ->chunkById(100, function ($subscricoes) {
                foreach ($subscricoes as $subscricao) {
                    try {
                        DB::transaction(function () use ($subscricao) {
                            $atual = Subscricao::query()->lockForUpdate()->find($subscricao->id);

                            if (! $atual || $atual->estado !== 'pendente') {
                                return;
                            }

                            $temPagamentoVivo = Pagamento::query()
                                ->where('subscricao_id', $atual->id)
                                ->whereIn('estado', ['pendente', 'confirmado'])
                                ->exists();

                            if (! $temPagamentoVivo) {
                                $atual->update(['estado' => 'cancelada', 'cancelada_em' => now()]);
                            }
                        });
                    } catch (Throwable $e) {
                        Log::error('Falha ao cancelar subscrição sem pagamento', [
                            'subscricao_id' => $subscricao->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    protected function terminarTrials(TenantService $tenantService): void
    {
        Empresa::query()
            ->where('estado_subscricao', 'trial')
            ->whereNotNull('subscricao_expira_em')
            ->where('subscricao_expira_em', '<', now())
            ->chunkById(100, function ($empresas) use ($tenantService) {
                foreach ($empresas as $empresa) {
                    try {
                        DB::transaction(function () use ($empresa, $tenantService) {
                            $atual = Empresa::query()->lockForUpdate()->find($empresa->id);

                            if (
                                ! $atual
                                || $atual->estado_subscricao !== 'trial'
                                || ! $atual->subscricao_expira_em
                                || $atual->subscricao_expira_em->isFuture()
                            ) {
                                return;
                            }

                            $tenantService->suspender($atual);
                        });
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
            ->chunkById(100, function ($subscricoes) use ($tenantService) {
                foreach ($subscricoes as $subscricao) {
                    try {
                        DB::transaction(function () use ($subscricao, $tenantService) {
                            $atual = Subscricao::query()->lockForUpdate()->find($subscricao->id);

                            if (
                                ! $atual
                                || $atual->estado !== 'ativa'
                                || ! $atual->termina_em
                                || $atual->termina_em->isFuture()
                            ) {
                                return;
                            }

                            $empresa = Empresa::query()->lockForUpdate()->find($atual->empresa_id);

                            $atual->update(['estado' => 'expirada']);

                            // Só suspende se a empresa continua vencida: um pagamento
                            // confirmado entretanto já empurrou a data para o futuro.
                            if (
                                $empresa
                                && in_array($empresa->estado_subscricao, ['ativa', 'trial'], true)
                                && (! $empresa->subscricao_expira_em || ! $empresa->subscricao_expira_em->isFuture())
                            ) {
                                $tenantService->suspender($empresa);
                            }
                        });
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
                        DB::transaction(function () use ($empresa, $tenantService) {
                            $atual = Empresa::query()->lockForUpdate()->find($empresa->id);

                            if (
                                ! $atual
                                || $atual->estado_subscricao !== 'suspensa'
                                || ! $atual->periodo_tolerancia_ate
                                || $atual->periodo_tolerancia_ate->isFuture()
                            ) {
                                return;
                            }

                            $tenantService->expirar($atual);
                        });
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
                        // Idempotente: se já há referência válida, não duplica.
                        $subscricaoService->gerarRenovacao($subscricao);
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
