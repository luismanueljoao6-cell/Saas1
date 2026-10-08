<?php

namespace Modules\Subscricoes\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Subscricao;
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
     * a empresa no Core, e notifica os utilizadores ativos da empresa.
     *
     * Um pagamento feito ANTES do fim da subscrição atual estende-a: os dias
     * que ainda restavam somam-se, nunca se perdem.
     *
     * @throws Throwable
     */
    public function confirmar(Pagamento $pagamento, array $payloadBruto = [], ?User $confirmadoPor = null): Pagamento
    {
        if ($pagamento->estado === 'confirmado') {
            Log::info('Pagamento já estava confirmado — notificação ignorada em segurança', [
                'pagamento_id' => $pagamento->id,
            ]);

            return $pagamento;
        }

        try {
            // Bypass explícito e intencional: esta operação é despoletada
            // pelo gateway (webhook) ou por um super admin — nenhum tem um
            // "tenant atual". Ver TenantManager::semTenant().
            return $this->tenantManager->semTenant(function () use ($pagamento, $payloadBruto, $confirmadoPor) {
                return DB::transaction(function () use ($pagamento, $payloadBruto, $confirmadoPor) {
                    // Relê com lock: dois webhooks duplicados em paralelo
                    // não podem confirmar (e somar dias) duas vezes.
                    $pagamento = Pagamento::query()->lockForUpdate()->findOrFail($pagamento->id);

                    if ($pagamento->estado === 'confirmado') {
                        return $pagamento;
                    }

                    $subscricao = $pagamento->subscricao()->with('plano')->firstOrFail();
                    $empresa = $subscricao->empresa;
                    $agora = now();

                    $base = $agora;
                    if ($empresa->estado_subscricao === 'ativa' && $empresa->subscricao_expira_em?->isFuture()) {
                        $base = $empresa->subscricao_expira_em;
                    }
                    $terminaEm = $base->copy()->addDays($subscricao->plano->periodo_dias);

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

                    // Uma empresa só tem uma subscrição ativa de cada vez
                    // (mudança de plano): as anteriores ficam encerradas.
                    Subscricao::query()
                        ->where('empresa_id', $empresa->id)
                        ->where('estado', 'ativa')
                        ->where('id', '!=', $subscricao->id)
                        ->update(['estado' => 'expirada']);

                    $this->tenantService->ativar($empresa, $terminaEm);

                    $empresa->utilizadores()->where('ativo', true)->get()
                        ->each(fn (User $utilizador) => $utilizador->notify(new PagamentoConfirmadoNotification(
    (string) $pagamento->valor,
    $pagamento->moeda,
    $subscricao->termina_em->format('d/m/Y'),
)));

                    Log::info('Pagamento confirmado e subscrição ativada', [
                        'pagamento_id' => $pagamento->id,
                        'empresa_id' => $empresa->id,
                        'termina_em' => $terminaEm->toDateTimeString(),
                        'confirmado_por' => $confirmadoPor?->id ? "user:{$confirmadoPor->id}" : 'gateway',
                    ]);

                    // Relação carregada AQUI, ainda dentro do bypass: um
                    // lazy-load mais tarde seria bloqueado pela TenantScope.
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
     * Lança exceção — em vez de devolver null — de propósito: um webhook
     * para uma referência desconhecida é uma situação anómala que deve
     * ficar registada, nunca ser ignorada silenciosamente.
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
