<?php

namespace Modules\Subscricoes\Services;

use DomainException;
use Illuminate\Support\Collection;
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
    ) {}

    /**
     * Ponto único de confirmação de pagamento — chamado tanto pelo Job que
     * processa o webhook como pela confirmação manual (transferência
     * bancária reconciliada por um super admin).
     *
     * Um pagamento feito ANTES do fim da subscrição atual estende-a: os dias
     * que ainda restavam somam-se, nunca se perdem.
     *
     * @param  string|null  $valorRecebido  Valor pago segundo o gateway. Se
     *                                      informado e inferior ao devido, lança DomainException
     *                                      e NADA é confirmado. Nulo = sem verificação (confirmação manual).
     *
     * @throws DomainException|Throwable
     */
    public function confirmar(Pagamento $pagamento, array $payloadBruto = [], ?User $confirmadoPor = null, ?string $valorRecebido = null): Pagamento
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
            return $this->tenantManager->semTenant(function () use ($pagamento, $payloadBruto, $confirmadoPor, $valorRecebido) {
                [$resultado, $destinatarios, $aviso] = DB::transaction(function () use ($pagamento, $payloadBruto, $confirmadoPor, $valorRecebido) {
                    // Relê com lock: dois webhooks duplicados em paralelo
                    // não podem confirmar (e somar dias) duas vezes.
                    $pagamento = Pagamento::query()->lockForUpdate()->findOrFail($pagamento->id);

                    if ($pagamento->estado === 'confirmado') {
                        return [$pagamento, collect(), null];
                    }

                    $this->validarValorRecebido($pagamento, $valorRecebido);

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

                    Log::info('Pagamento confirmado e subscrição ativada', [
                        'pagamento_id' => $pagamento->id,
                        'empresa_id' => $empresa->id,
                        'termina_em' => $terminaEm->toDateTimeString(),
                        'confirmado_por' => $confirmadoPor?->id ? "user:{$confirmadoPor->id}" : 'gateway',
                    ]);

                    return [
                        // Relação carregada AQUI, ainda dentro do bypass: um
                        // lazy-load mais tarde seria bloqueado pela TenantScope.
                        $pagamento->fresh(['subscricao.plano']),
                        $empresa->utilizadores()->where('ativo', true)->get(),
                        [
                            'valor' => (string) $pagamento->valor,
                            'moeda' => $pagamento->moeda,
                            'termina_em' => $terminaEm->format('d/m/Y'),
                        ],
                    ];
                });

                // Depois do commit: uma falha de e-mail NUNCA desfaz um
                // pagamento já recebido e ativado.
                if ($aviso !== null) {
                    $this->notificarConfirmacao($destinatarios, $aviso);
                }

                return $resultado;
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

    /**
     * Aceita igual ou superior ao devido (comparação em cêntimos, sem float
     * a decidir arredondamentos de fronteira).
     */
    protected function validarValorRecebido(Pagamento $pagamento, ?string $valorRecebido): void
    {
        if ($valorRecebido === null || $valorRecebido === '') {
            return;
        }

        $recebido = (int) round(((float) $valorRecebido) * 100);
        $devido = (int) round(((float) $pagamento->valor) * 100);

        if ($recebido < $devido) {
            throw new DomainException(
                "Valor recebido ({$valorRecebido}) inferior ao devido ({$pagamento->valor}) no pagamento {$pagamento->id}."
            );
        }
    }

    /**
     * @param  Collection<int, User>  $destinatarios
     * @param  array{valor: string, moeda: string, termina_em: string}  $aviso
     */
    protected function notificarConfirmacao(Collection $destinatarios, array $aviso): void
    {
        foreach ($destinatarios as $utilizador) {
            try {
                $utilizador->notify(new PagamentoConfirmadoNotification(
                    $aviso['valor'],
                    $aviso['moeda'],
                    $aviso['termina_em'],
                ));
            } catch (Throwable $e) {
                Log::warning('Pagamento confirmado, mas a notificação falhou', [
                    'utilizador_id' => $utilizador->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }
}
