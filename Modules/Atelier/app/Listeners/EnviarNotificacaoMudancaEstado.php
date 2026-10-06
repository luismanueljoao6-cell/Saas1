<?php

namespace Modules\Atelier\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;

/**
 * Traduz uma mudança de estado (requisito A.2) numa notificação ao cliente
 * (requisito C.1). 'pendente', 'em_corte' e 'em_costura' são passos
 * internos de produção — não geram notificação, para não bombardear o
 * cliente com atualizações que não lhe interessam.
 */
class EnviarNotificacaoMudancaEstado implements ShouldQueue
{
    public function __construct(protected NotificadorClienteService $notificador)
    {
    }

    public function handle(PedidoMudouEstado $event): void
    {
        $pedido = $event->pedido;
        $cliente = $pedido->cliente;

        if (! $cliente) {
            return;
        }

        $mensagem = $this->mensagemPara($pedido);

        if ($mensagem === null) {
            return;
        }

        $this->notificador->notificar(
            $cliente,
            "Atualização do seu pedido — {$pedido->empresa->nome_comercial}",
            $mensagem,
        );
    }

    protected function mensagemPara(Pedido $pedido): ?string
    {
        return match ($pedido->status) {
            'primeira_prova' => 'A sua peça está pronta para a 1ª prova! Entraremos em contacto para agendar.',
            'ajustes' => 'A sua peça está em fase de ajustes finais.',
            'pronto_para_retirada' => $this->mensagemProntoParaRetirada($pedido),
            'entregue' => 'A sua peça foi entregue. Obrigado por confiar no nosso atelier!',
            'cancelado' => 'O seu pedido foi cancelado.'.($pedido->motivo_cancelamento ? " Motivo: {$pedido->motivo_cancelamento}" : ''),
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
