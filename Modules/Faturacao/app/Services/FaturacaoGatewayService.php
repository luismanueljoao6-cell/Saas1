<?php

namespace Modules\Faturacao\Services;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Contracts\FaturacaoGateway;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;

/**
 * Fachada estável para os módulos consumidores da Faturação.
 * Não contém regras fiscais: delega sempre nos serviços de domínio oficiais.
 */
class FaturacaoGatewayService implements FaturacaoGateway
{
    public function __construct(
        protected FaturaService $faturas,
        protected ReciboService $recibos,
    ) {}

    public function criarRascunho(
        Empresa $empresa,
        Cliente $cliente,
        array $linhas,
        ?string $observacoes = null,
    ): Fatura {
        return $this->faturas->criarRascunho($empresa, $cliente, $linhas, $observacoes);
    }

    public function emitirFatura(Fatura $fatura): Fatura
    {
        return $this->faturas->emitir($fatura);
    }

    public function emitirRecibo(
        int $empresaId,
        int $clienteId,
        string|int $valor,
        string $meioPagamento,
        ?int $faturaId = null,
    ): Recibo {
        return $this->recibos->emitir($empresaId, $clienteId, $valor, $meioPagamento, $faturaId);
    }

    public function saldoEmCentimos(Fatura $fatura): int
    {
        return $this->recibos->saldoEmCentimos($fatura);
    }

    public function resumoDaFatura(Fatura $fatura): array
    {
        return $this->recibos->resumoDaFatura($fatura);
    }
}
