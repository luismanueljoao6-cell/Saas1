<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;

/**
 * Única porta de entrada para mudar Pedido::status. O controller nunca
 * escreve `$pedido->update(['status' => ...])` diretamente — passa sempre
 * por aqui, para que a validação de transições e o disparo do evento (que
 * aciona as notificações ao cliente, requisito C) fiquem garantidos em
 * todos os sítios que mexem no estado de um pedido.
 */
class PedidoStatusService
{
    private const TRANSICOES_PERMITIDAS = [
        'pendente' => ['em_corte', 'cancelado'],
        'em_corte' => ['em_costura', 'cancelado'],
        'em_costura' => ['primeira_prova', 'cancelado'],
        'primeira_prova' => ['ajustes', 'pronto_para_retirada', 'cancelado'],
        'ajustes' => ['primeira_prova', 'pronto_para_retirada', 'cancelado'],
        'pronto_para_retirada' => ['entregue', 'cancelado'],
        'entregue' => [],
        'cancelado' => [],
    ];

    public function avancarPara(Pedido $pedido, string $novoEstado, ?string $motivoCancelamento = null): Pedido
    {
        $estadoAnterior = $pedido->status;

        $permitidos = self::TRANSICOES_PERMITIDAS[$estadoAnterior] ?? [];

        if (! in_array($novoEstado, $permitidos, true)) {
            throw PedidoException::transicaoInvalida($estadoAnterior, $novoEstado);
        }

        return DB::transaction(function () use ($pedido, $novoEstado, $motivoCancelamento, $estadoAnterior) {
            $pedido->status = $novoEstado;

            if ($novoEstado === 'cancelado') {
                $pedido->motivo_cancelamento = $motivoCancelamento;
            }

            $pedido->save();

            Log::info('Pedido mudou de estado', [
                'pedido_id' => $pedido->id,
                'de' => $estadoAnterior,
                'para' => $novoEstado,
            ]);

            event(new PedidoMudouEstado($pedido, $estadoAnterior));

            return $pedido;
        });
    }

    /** @return array<int, string> Estados para os quais este pedido pode avançar agora — usado para desenhar os botões de ação na view. */
    public function proximosEstadosPossiveis(Pedido $pedido): array
    {
        return self::TRANSICOES_PERMITIDAS[$pedido->status] ?? [];
    }
}
