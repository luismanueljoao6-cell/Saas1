<?php

namespace Modules\Faturacao\Services;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Contracts\FaturacaoGateway;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Support\ConfiguracaoFiscalEmpresa;

/**
 * Fachada estável para os módulos consumidores da Faturação.
 * Não contém regras fiscais: delega sempre nos serviços de domínio oficiais.
 */
class FaturacaoGatewayService implements FaturacaoGateway
{
    public function __construct(
        protected FaturaService $faturas,
        protected ReciboService $recibos,
        protected AbatimentoAdiantamentoService $abatimentos,
        protected NotaCreditoDebitoService $notas,
    ) {}

    public function criarRascunho(
        Empresa $empresa,
        Cliente $cliente,
        array $linhas,
        ?string $observacoes = null,
    ): Fatura {
        return $this->faturas->criarRascunho($empresa, $cliente, $linhas, $observacoes);
    }

    public function criarRascunhoAdiantamento(
        Empresa $empresa,
        Cliente $cliente,
        array $linhas,
        ?string $observacoes = null,
    ): Fatura {
        return $this->faturas->criarRascunhoAdiantamento($empresa, $cliente, $linhas, $observacoes);
    }

    public function criarNotaCredito(
        int $empresaId,
        int $clienteId,
        int $faturaId,
        string $motivo,
        array $linhas,
    ): NotaCreditoDebito {
        return $this->notas->criarRascunho($empresaId, $clienteId, 'credito', $motivo, $linhas, $faturaId);
    }

    public function criarNotaDebito(
        int $empresaId,
        int $clienteId,
        string $motivo,
        array $linhas,
        ?int $faturaId = null,
    ): NotaCreditoDebito {
        return $this->notas->criarRascunho($empresaId, $clienteId, 'debito', $motivo, $linhas, $faturaId);
    }

    public function anularFatura(Fatura $fatura, string $motivo): NotaCreditoDebito
    {
        return $this->notas->criarParaAnularFatura($fatura, $motivo);
    }

    public function emitirNota(NotaCreditoDebito $nota): NotaCreditoDebito
    {
        return $this->notas->emitir($nota);
    }

    public function taxaIvaPadrao(int $empresaId): string
    {
        return ConfiguracaoFiscalEmpresa::taxaPadrao($empresaId);
    }

    public function emitirRegularizacao(
        int $empresaId,
        int $clienteId,
        string|int $valor,
        string $meioPagamento,
        int $faturaId,
    ): Recibo {
        return $this->recibos->emitirRegularizacao($empresaId, $clienteId, $valor, $meioPagamento, $faturaId);
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

    public function abaterAdiantamento(
        int $empresaId,
        int $faturaAdiantamentoId,
        int $faturaFinalId,
        string|int $valor,
        string $chave,
    ): array {
        return $this->abatimentos->abater($empresaId, $faturaAdiantamentoId, $faturaFinalId, $valor, $chave);
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
