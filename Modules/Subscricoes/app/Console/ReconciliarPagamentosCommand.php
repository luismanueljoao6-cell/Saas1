<?php

namespace Modules\Subscricoes\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\TenantManager;
use Modules\Subscricoes\Jobs\ProcessarPagamentoConfirmadoJob;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\WebhookEvento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Throwable;

/**
 * Reconciliação financeira. Corre de 10 em 10 minutos (scheduler) e pode ser
 * corrida à mão:
 *
 *   php artisan subscricoes:reconciliar
 *   php artisan subscricoes:reconciliar --resolver=12 --resolver=13
 *
 * Faz três coisas: reprocessa webhooks guardados e não processados; repete
 * cancelamentos remotos que falharam; e lista anomalias que exigem um humano.
 * Devolve código de saída 1 se houver anomalias (útil para monitorização).
 */
class ReconciliarPagamentosCommand extends Command
{
    protected $signature = 'subscricoes:reconciliar
        {--minutos=5 : Idade mínima (min.) de um evento pendente antes de ser reprocessado}
        {--max-tentativas=10 : Tentativas máximas por evento}
        {--resolver=* : IDs de eventos já tratados manualmente (passam a "resolvido")}';

    protected $description = 'Reprocessa webhooks pendentes, repete cancelamentos remotos e lista anomalias financeiras';

    public function handle(TenantManager $tenantManager, GatewayPagamentoInterface $gateway): int
    {
        return $tenantManager->semTenant(function () use ($gateway): int {
            $resolvidos = $this->resolverManuais();
            $reprocessados = $this->reprocessarEventos($gateway);
            $cancelados = $this->repetirCancelamentosRemotos($gateway);
            $descartados = $this->descartarPagamentosSemReferencia();
            $anomalias = $this->detetarAnomalias();

            $this->info("Eventos resolvidos manualmente: {$resolvidos}");
            $this->info("Eventos reprocessados: {$reprocessados}");
            $this->info("Cancelamentos remotos repetidos com sucesso: {$cancelados}");
            $this->info("Pagamentos sem referência descartados: {$descartados}");

            if ($anomalias === []) {
                $this->info('Sem anomalias.');

                return self::SUCCESS;
            }

            $this->table(['Tipo', 'Detalhe'], $anomalias);

            Log::critical('Reconciliação de subscrições encontrou anomalias', [
                'total' => count($anomalias),
                'anomalias' => $anomalias,
            ]);

            return self::FAILURE;
        });
    }

    protected function resolverManuais(): int
    {
        $ids = array_filter(array_map('intval', (array) $this->option('resolver')));

        if ($ids === []) {
            return 0;
        }

        return WebhookEvento::query()->whereIn('id', $ids)->update([
            'estado' => WebhookEvento::RESOLVIDO,
            'processado_em' => now(),
        ]);
    }

    /**
     * Um pagamento 'pendente' sem referência há mais de 15 minutos nunca chegou
     * a ser mostrado a ninguém (o pedido que o criou morreu a meio): não há
     * nada a cobrar nem a reconciliar. Expira-o e cancela a subscrição ainda
     * pendente, em vez de o deixar a bloquear a empresa e a gerar alertas.
     */
    protected function descartarPagamentosSemReferencia(): int
    {
        $total = 0;

        Pagamento::query()
            ->where('estado', 'pendente')
            ->whereNull('referencia_externa')
            ->where('created_at', '<', now()->subMinutes(15))
            ->with('subscricao')
            ->chunkById(100, function ($pagamentos) use (&$total) {
                foreach ($pagamentos as $pagamento) {
                    try {
                        $pagamento->update(['estado' => 'expirado']);

                        if ($pagamento->subscricao?->estado === 'pendente') {
                            $pagamento->subscricao->update(['estado' => 'cancelada', 'cancelada_em' => now()]);
                        }

                        $total++;
                    } catch (Throwable $e) {
                        Log::error('Reconciliação: falha ao descartar pagamento sem referência', [
                            'pagamento_id' => $pagamento->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($total > 0) {
            Log::warning('Reconciliação: pagamentos sem referência descartados', ['total' => $total]);
        }

        return $total;
    }

    protected function reprocessarEventos(GatewayPagamentoInterface $gateway): int
    {
        $limite = now()->subMinutes((int) $this->option('minutos'));
        $max = (int) $this->option('max-tentativas');
        $total = 0;

        WebhookEvento::query()
            ->where('gateway', $gateway->identificador())
            ->whereIn('estado', [WebhookEvento::RECEBIDO, WebhookEvento::FALHADO])
            ->where('tentativas', '<', $max)
            ->where('updated_at', '<', $limite)
            ->chunkById(100, function ($eventos) use ($gateway, &$total) {
                foreach ($eventos as $evento) {
                    try {
                        if ($evento->referencia_externa) {
                            $notificacao = [
                                'referencia_externa' => $evento->referencia_externa,
                                'valor' => $evento->valor,
                                'payload' => json_decode($evento->corpo_bruto, true) ?: [],
                            ];
                        } else {
                            $pedido = Request::create('/', 'POST', [], [], [], [
                                'CONTENT_TYPE' => 'application/json',
                            ], $evento->corpo_bruto);

                            $notificacao = $gateway->interpretarNotificacao($pedido);

                            $evento->update([
                                'referencia_externa' => $notificacao['referencia_externa'],
                                'valor' => $notificacao['valor'] ?? null,
                            ]);
                        }
                    } catch (Throwable $e) {
                        $evento->update([
                            'estado' => WebhookEvento::ILEGIVEL,
                            'motivo_falha' => mb_substr($e->getMessage(), 0, 1000),
                        ]);

                        continue;
                    }

                    try {
                        // `tentativas` já é incrementado pelo próprio job (atualizarEvento):
                        // contar também aqui duplicava o contador a cada reprocessamento.
                        ProcessarPagamentoConfirmadoJob::dispatch(
                            $evento->gateway,
                            $notificacao['referencia_externa'],
                            $notificacao['payload'],
                            $notificacao['valor'] ?? null,
                            $evento->id,
                        );

                        $total++;
                    } catch (Throwable $e) {
                        Log::error('Reconciliação: falha ao reprocessar webhook', [
                            'evento_id' => $evento->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $total;
    }

    protected function repetirCancelamentosRemotos(GatewayPagamentoInterface $gateway): int
    {
        if (! method_exists($gateway, 'cancelarReferencia')) {
            return 0;
        }

        $sucessos = 0;

        Pagamento::query()
            ->where('cancelamento_remoto_pendente', true)
            ->where('estado', '!=', 'confirmado')
            ->whereNotNull('referencia_externa')
            ->chunkById(50, function ($pagamentos) use ($gateway, &$sucessos) {
                foreach ($pagamentos as $pagamento) {
                    try {
                        $gateway->cancelarReferencia($pagamento);

                        $pagamento->update([
                            'cancelamento_remoto_pendente' => false,
                            'cancelamento_remoto_erro' => null,
                        ]);

                        $sucessos++;
                    } catch (Throwable $e) {
                        $pagamento->update([
                            'cancelamento_remoto_tentativas' => $pagamento->cancelamento_remoto_tentativas + 1,
                            'cancelamento_remoto_erro' => mb_substr($e->getMessage(), 0, 500),
                        ]);
                    }
                }
            });

        return $sucessos;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    protected function detetarAnomalias(): array
    {
        $max = (int) $this->option('max-tentativas');
        $linhas = [];

        WebhookEvento::query()
            ->where(function ($q) use ($max) {
                $q->whereIn('estado', [WebhookEvento::REJEITADO, WebhookEvento::ILEGIVEL])
                    ->orWhere(fn ($q2) => $q2->where('estado', WebhookEvento::FALHADO)->where('tentativas', '>=', $max));
            })
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (WebhookEvento $e) use (&$linhas) {
                $linhas[] = [
                    "evento {$e->estado}",
                    "#{$e->id} ref={$e->referencia_externa} valor={$e->valor} — {$e->motivo_falha}",
                ];
            });

        Pagamento::query()
            ->where('estado', 'pendente')
            ->whereNull('referencia_externa')
            ->where('created_at', '<', now()->subMinutes(15))
            ->limit(200)
            ->get()
            ->each(function (Pagamento $p) use (&$linhas) {
                $linhas[] = ['pagamento sem referência', "#{$p->id} — criação remota possivelmente incompleta; verificar na ProxyPay"];
            });

        Pagamento::query()
            ->where('estado', 'confirmado')
            ->whereHas('subscricao', fn ($q) => $q->where('estado', 'pendente'))
            ->limit(200)
            ->get()
            ->each(function (Pagamento $p) use (&$linhas) {
                $linhas[] = ['pago mas não ativado', "pagamento #{$p->id} confirmado com subscrição ainda pendente"];
            });

        Pagamento::query()
            ->where('cancelamento_remoto_pendente', true)
            ->limit(200)
            ->get()
            ->each(function (Pagamento $p) use (&$linhas) {
                $linhas[] = ['referência não cancelada', "pagamento #{$p->id} ref={$p->referencia_externa} — {$p->cancelamento_remoto_erro}"];
            });

        return $linhas;
    }
}
