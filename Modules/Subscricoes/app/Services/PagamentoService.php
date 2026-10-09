<?php

namespace Modules\Subscricoes\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Exceptions\PagamentoRejeitadoException;
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
     * Ponto único de confirmação de pagamento (webhook ou reconciliação
     * manual por super admin).
     *
     * Ordem de locks (igual à do job diário, para evitar deadlocks):
     * Pagamento -> Subscricao -> Empresa.
     * As notificações só saem DEPOIS do commit, e uma falha de e-mail nunca
     * reverte a confirmação do pagamento.
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
            return $this->tenantManager->semTenant(function () use ($pagamento, $payloadBruto, $confirmadoPor) {
                $resultado = DB::transaction(function () use ($pagamento, $payloadBruto, $confirmadoPor) {
                    $pagamento = Pagamento::query()->lockForUpdate()->findOrFail($pagamento->id);

                    if ($pagamento->estado === 'confirmado') {
                        return ['novo' => false, 'pagamento' => $pagamento];
                    }

                    $this->validarConfirmavel($pagamento, $payloadBruto, $confirmadoPor);

                    $subscricao = Subscricao::query()
                        ->lockForUpdate()
                        ->with('plano')
                        ->findOrFail($pagamento->subscricao_id);

                    // Lock da empresa: dois pagamentos simultâneos da mesma
                    // empresa já não leem a mesma data-base nem perdem dias.
                    $empresa = Empresa::query()->lockForUpdate()->findOrFail($subscricao->empresa_id);

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
                        'novo' => true,
                        'pagamento' => $pagamento,
                        'empresa' => $empresa,
                        'termina_em' => $terminaEm,
                    ];
                }, 3);

                if ($resultado['novo']) {
                    $this->notificarConfirmacao($resultado['empresa'], $resultado['pagamento'], $resultado['termina_em']);
                }

                return $resultado['pagamento']->fresh(['subscricao.plano']);
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
     * Referência desconhecida lança exceção (o Job tenta de novo com backoff).
     * Fallback: se a referência foi criada no gateway mas nunca ficou gravada
     * localmente, tenta ligar o webhook ao Pagamento pelos custom_fields que
     * nós próprios enviámos (só para pagamentos ainda SEM referência).
     *
     * @throws GatewayPagamentoException
     */
    public function localizarPorReferencia(string $referenciaExterna, array $payload = []): Pagamento
    {
        $pagamento = $this->tenantManager->semTenant(
            fn () => Pagamento::where('referencia_externa', $referenciaExterna)->first()
        );

        if (! $pagamento) {
            $invoice = $payload['custom_fields']['invoice'] ?? null;
            $empresaId = $payload['custom_fields']['empresa_id'] ?? null;

            if (is_numeric($invoice) && is_numeric($empresaId)) {
                $pagamento = $this->tenantManager->semTenant(fn () => Pagamento::query()
                    ->whereKey((int) $invoice)
                    ->where('empresa_id', (int) $empresaId)
                    ->whereNull('referencia_externa')
                    ->first());

                if ($pagamento) {
                    Log::warning('Webhook ligado a pagamento sem referência gravada (recuperação)', [
                        'pagamento_id' => $pagamento->id,
                        'referencia_externa' => $referenciaExterna,
                    ]);

                    $this->tenantManager->semTenant(
                        fn () => $pagamento->update(['referencia_externa' => $referenciaExterna])
                    );
                }
            }
        }

        if (! $pagamento) {
            throw GatewayPagamentoException::pagamentoNaoEncontrado($referenciaExterna);
        }

        return $pagamento;
    }

    /**
     * Aceita pagamentos 'pendente' e 'expirado' (pagamento tardio: o dinheiro
     * já entrou, deve ativar). Qualquer outro estado é recusado. Para
     * notificações de gateway, o valor pago tem de existir e não pode ser
     * inferior ao valor esperado.
     */
    protected function validarConfirmavel(Pagamento $pagamento, array $payload, ?User $confirmadoPor): void
    {
        if (! in_array($pagamento->estado, ['pendente', 'expirado'], true)) {
            throw PagamentoRejeitadoException::estadoInvalido($pagamento);
        }

        if ($pagamento->estado === 'expirado') {
            Log::warning('Pagamento tardio sobre referência expirada — a confirmar.', [
                'pagamento_id' => $pagamento->id,
            ]);
        }

        // Reconciliação manual por super admin: a validação do valor é humana.
        if ($confirmadoPor !== null) {
            return;
        }

        $valorPago = $this->extrairValorPago($payload);

        if ($valorPago === null) {
            if (Config::get('subscricoes.exigir_valor_webhook', true)) {
                throw PagamentoRejeitadoException::valorAusente($pagamento);
            }

            Log::warning('Webhook sem valor pago aceite (SUBSCRICOES_EXIGIR_VALOR_WEBHOOK=false).', [
                'pagamento_id' => $pagamento->id,
            ]);

            return;
        }

        $pagoCentimos = (int) round($valorPago * 100);
        $esperadoCentimos = (int) round((float) $pagamento->valor * 100);

        if ($pagoCentimos < $esperadoCentimos) {
            throw PagamentoRejeitadoException::valorDivergente($pagamento, $valorPago);
        }

        if ($pagoCentimos > $esperadoCentimos) {
            Log::warning('Valor pago superior ao esperado — a confirmar; rever manualmente.', [
                'pagamento_id' => $pagamento->id,
                'valor_pago' => $valorPago,
                'valor_esperado' => (string) $pagamento->valor,
            ]);
        }
    }

    /**
     * CONFIRMA o nome do campo na documentação/sandbox da ProxyPay.
     */
    protected function extrairValorPago(array $payload): ?float
    {
        $valor = $payload['amount'] ?? $payload['valor'] ?? null;

        return is_numeric($valor) ? (float) $valor : null;
    }

    protected function notificarConfirmacao(Empresa $empresa, Pagamento $pagamento, \DateTimeInterface $terminaEm): void
    {
        $empresa->utilizadores()->where('ativo', true)->get()->each(function (User $utilizador) use ($pagamento, $terminaEm) {
            try {
                $utilizador->notify(new PagamentoConfirmadoNotification(
                    (string) $pagamento->valor,
                    $pagamento->moeda,
                    $terminaEm->format('d/m/Y'),
                ));
            } catch (Throwable $e) {
                Log::error('Falha ao notificar pagamento confirmado (a confirmação mantém-se).', [
                    'pagamento_id' => $pagamento->id,
                    'utilizador_id' => $utilizador->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        });
    }
}
