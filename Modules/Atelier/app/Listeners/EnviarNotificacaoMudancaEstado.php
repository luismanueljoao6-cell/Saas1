<?php

namespace Modules\Atelier\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Core\Services\TenantManager;

/**
 * Traduz uma mudança de estado (requisito A.2) numa notificação ao cliente
 * (requisito C.1). O evento transporta apenas IDs; o pedido e as relações
 * são recarregados dentro do tenant proprietário, sem bypass global.
 *
 * O motivo do cancelamento é uma nota INTERNA (pode conter observações que
 * o cliente não deve ler) — nunca é enviado na mensagem ao cliente.
 */
class EnviarNotificacaoMudancaEstado implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        protected NotificadorClienteService $notificador,
        protected TenantManager $tenantManager,
    ) {}

    public function handle(PedidoMudouEstado $event): void
    {
        if ($event->pedidoId < 1 || $event->empresaId < 1) {
            return;
        }

        $this->tenantManager->comEmpresa($event->empresaId, function () use ($event): void {
            $pedido = Pedido::query()
                ->where('empresa_id', $event->empresaId)
                ->with(['cliente', 'empresa'])
                ->find($event->pedidoId);

            if (! $pedido || (int) $pedido->empresa_id !== $event->empresaId) {
                return;
            }

            $cliente = $pedido->cliente;
            $empresa = $pedido->empresa;

            // A FK de cliente_id não é composta com empresa_id. A relação só
            // pode ser usada se cliente, pedido e empresa forem do mesmo tenant.
            if (
                ! $cliente
                || (int) $cliente->empresa_id !== $event->empresaId
                || ! $empresa
                || (int) $empresa->id !== $event->empresaId
            ) {
                return;
            }

            $mensagem = $this->mensagemPara($pedido);

            if ($mensagem === null) {
                return;
            }

            $this->notificador->notificar(
                $cliente,
                "Atualização do seu pedido — {$empresa->nome_comercial}",
                $mensagem,
            );
        });
    }

    protected function mensagemPara(Pedido $pedido): ?string
    {
        return match ($pedido->status) {
            'primeira_prova' => 'A sua peça está pronta para a 1ª prova! Entraremos em contacto para agendar.',
            'ajustes' => 'A sua peça está em fase de ajustes finais.',
            'pronto_para_retirada' => $this->mensagemProntoParaRetirada($pedido),
            'entregue' => 'A sua peça foi entregue. Obrigado por confiar no nosso atelier!',
            'cancelado' => 'O seu pedido foi cancelado. Contacte-nos se tiver alguma dúvida.',
            default => null,
        };
    }

    protected function mensagemProntoParaRetirada(Pedido $pedido): string
    {
        $saldo = $pedido->saldoEmFalta();

        if ($saldo > 0) {
            $valorFormatado = number_format($saldo, 2, ',', '.');

            return "A sua peça está pronta para retirada! Saldo pendente: {$valorFormatado} AOA.";
        }

        return 'A sua peça está pronta para retirada! Não há saldo pendente.';
    }
}
