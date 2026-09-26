<?php

namespace Modules\Subscricoes\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Notifications\PagamentoConfirmadoNotification;
use Throwable;

class PagamentoService
{
    public function __construct(
        protected TenantService $tenantService,
        protected TenantManager $tenantManager,
    ) {
    }

    /**
     * Ponto único de confirmação de pagamento — chamado tanto pelo Job que
     * processa o webhook como pela confirmação manual (transferência
     * bancária reconciliada por um super admin). Ativa a subscrição, ativa
     * a empresa no Core, e notifica os administradores da empresa.
     *
     * @throws Throwable
     */
    public function confirmar(Pagamento $pagamento, array $payloadBruto = [], ?User $confirmadoPor = null): Pagamento
    {
        if ($pagamento->estado === 'confirmado') {
            // Idempotência: um gateway pode reenviar a mesma notificação
            // mais do que uma vez — confirmar duas vezes não deve duplicar
            // o período de subscrição.
            Log::info('Pagamento já estava confirmado — notificação ignorada em segurança', [
                'pagamento_id' => $pagamento->id,
            ]);

            return $pagamento;
        }

        try {
            // Bypass explícito e intencional: esta operação é despoletada
            // pelo gateway de pagamento (webhook) ou por um super admin da
            // plataforma — nenhum dos dois tem um "tenant atual" no sentido
            // em que o IdentificarTenant o define. Sem este bypass, a
            // TenantScope (fail-closed) bloquearia a leitura tanto do
            // Pagamento como da Subscricao. Ver TenantManager::semTenant().
            return $this->tenantManager->semTenant(function () use ($pagamento, $payloadBruto, $confirmadoPor) {
                return DB::transaction(function () use ($pagamento, $payloadBruto, $confirmadoPor) {
                    $subscricao = $pagamento->subscricao()->with('plano')->firstOrFail();
                    $empresa = $subscricao->empresa;

                    $agora = now();
                    $terminaEm = $agora->copy()->addDays($subscricao->plano->periodo_dias);

                    $pagamento->update([
                        'estado' => 'confirmado',
                        'confirmado_em' => $agora,
                        'confirmado_por' => $confirmadoPor?->id,
                        'payload_bruto' => $payloadBruto ?: $pagamento->payload_bruto,
                    ]);

                    $subscricao->update([
                        'estado' => 'ativa',
                        'inicio_em' => $subscricao->inicio_em ?? $agora,
                        'termina_em' => $terminaEm,
                    ]);

                    $this->tenantService->ativar($empresa, $terminaEm);

                    $empresa->utilizadores()->where('ativo', true)->get()
                        ->each(fn (User $utilizador) => $utilizador->notify(new PagamentoConfirmadoNotification($pagamento, $subscricao)));

                    Log::info('Pagamento confirmado e subscrição ativada', [
                        'pagamento_id' => $pagamento->id,
                        'empresa_id' => $empresa->id,
                        'termina_em' => $terminaEm->toDateTimeString(),
                        'confirmado_por' => $confirmadoPor?->id ? "user:{$confirmadoPor->id}" : 'gateway',
                    ]);

                    return $pagamento->fresh(['subscricao.plano']);
                });
            });
        } catch (Throwable $e) {
            Log::error('Falha ao confirmar pagamento', [
                'pagamento_id' => $pagamento->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Localiza o Pagamento correspondente a uma referência externa
     * devolvida pelo gateway. Lança exceção — em vez de devolver null — de
     * propósito: um webhook para uma referência desconhecida é uma
     * situação anómala que deve ficar registada, nunca ser ignorada
     * silenciosamente.
     *
     * @throws GatewayPagamentoException
     */
    public function localizarPorReferencia(string $referenciaExterna): Pagamento
    {
        $pagamento = $this->tenantManager->semTenant(
            fn () => Pagamento::where('referencia_externa', $referenciaExterna)->first()
        );

        if (! $pagamento) {
            throw GatewayPagamentoException::pagamentoNaoEncontrado($referenciaExterna);
        }

        return $pagamento;
    }
}
