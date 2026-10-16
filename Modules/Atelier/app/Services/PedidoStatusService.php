<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Support\Dinheiro;

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

    /**
     * @param  bool  $validarFaturacao  Quando true (default), a entrega exige a
     *                                  fatura final emitida e o saldo pago, e o
     *                                  cancelamento é recusado se já há fatura
     *                                  emitida (exige nota de crédito). Só
     *                                  desligar em testes/scripts de dados.
     */
    public function avancarPara(
        Pedido $pedido,
        string $novoEstado,
        ?string $motivoCancelamento = null,
        bool $validarFaturacao = true,
    ): Pedido {
        [$estadoAnterior] = DB::transaction(function () use ($pedido, $novoEstado, $motivoCancelamento, $validarFaturacao) {
            // Recarrega o estado dentro da transação e bloqueia a linha.
            // Sem isto, dois pedidos concorrentes podiam validar a mesma
            // transição antiga e depois sobrescrever um ao outro.
            $bloqueado = Pedido::query()
                ->where('empresa_id', $pedido->empresa_id)
                ->lockForUpdate()
                ->findOrFail($pedido->id);

            $estadoAnterior = $bloqueado->status;
            $permitidos = self::TRANSICOES_PERMITIDAS[$estadoAnterior] ?? [];

            if (! in_array($novoEstado, $permitidos, true)) {
                throw PedidoException::transicaoInvalida($estadoAnterior, $novoEstado);
            }

            if ($validarFaturacao) {
                if ($novoEstado === 'entregue') {
                    $this->exigirFaturacaoRegularizada($bloqueado);
                }

                if ($novoEstado === 'cancelado') {
                    $this->exigirQueNaoHaFaturaEmitida($bloqueado);
                }
            }

            $bloqueado->status = $novoEstado;

            if ($novoEstado === 'cancelado') {
                $bloqueado->motivo_cancelamento = $motivoCancelamento;
            }

            $bloqueado->save();

            // Mantém a instância que o controller entregou sincronizada,
            // com os dados relidos e gravados sob lock.
            $pedido->setRawAttributes($bloqueado->getAttributes(), true);

            Log::info('Pedido mudou de estado', [
                'pedido_id' => $bloqueado->id,
                'empresa_id' => $bloqueado->empresa_id,
                'de' => $estadoAnterior,
                'para' => $novoEstado,
            ]);

            return [$estadoAnterior];
        });

        // O evento só é disparado DEPOIS do commit: os listeners (notificação
        // ao cliente por WhatsApp/SMS/mail) nunca veem — nem avisam o cliente
        // de — um estado que acabou em rollback.
        event(new PedidoMudouEstado($pedido, $estadoAnterior));

        return $pedido;
    }

    /** @return array<int, string> Estados para os quais este pedido pode avançar agora — usado para desenhar os botões de ação na view. */
    public function proximosEstadosPossiveis(Pedido $pedido): array
    {
        return self::TRANSICOES_PERMITIDAS[$pedido->status] ?? [];
    }

    /** Entrega: fatura final emitida e, se sobrar saldo depois do sinal, recibo do saldo registado. */
    protected function exigirFaturacaoRegularizada(Pedido $pedido): void
    {
        $fatura = $this->faturaDe($pedido);

        if (! $fatura || ! $fatura->estaEmitido()) {
            throw PedidoException::entregaSemFaturaEmitida();
        }

        $saldo = Dinheiro::centimos($fatura->valor_total) - Dinheiro::centimos($pedido->valor_sinal ?? 0);

        if ($saldo > 0 && $pedido->recibo_saldo_final_id === null) {
            throw PedidoException::entregaComSaldoPorPagar();
        }
    }

    /** Cancelar um pedido com fatura emitida deixaria um documento fiscal órfão — exige nota de crédito. */
    protected function exigirQueNaoHaFaturaEmitida(Pedido $pedido): void
    {
        $fatura = $this->faturaDe($pedido);

        if ($fatura && $fatura->estaEmitido()) {
            throw PedidoException::cancelamentoComFaturaEmitida();
        }
    }

    protected function faturaDe(Pedido $pedido): ?Fatura
    {
        if (! $pedido->jaTemFaturaFinal()) {
            return null;
        }

        return Fatura::withoutGlobalScopes()
            ->where('empresa_id', $pedido->empresa_id)
            ->find($pedido->fatura_id);
    }
}
