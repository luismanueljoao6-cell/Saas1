<?php

namespace Modules\Faturacao\Contracts;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;

/**
 * API pública de integração para módulos de negócio.
 *
 * Atelier, Estúdio de Música e módulos futuros usam este contrato em vez de
 * acederem aos serviços internos de emissão. As regras fiscais, a numeração,
 * a assinatura, os limites e a imutabilidade permanecem no módulo Faturação.
 */
interface FaturacaoGateway
{
    /**
     * Valores monetários e taxas: texto decimal («1234.50», «14») ou inteiro — nunca float.
     * A quantidade pode ser inteira ou texto decimal («2.50»).
     *
     * @param  array<int, array{produto_id?: int|null, descricao: string, quantidade: int|string, preco_unitario: int|string, preco_unitario_base?: int|string|null, taxa_iva: int|string, motivo_isencao?: string|null, operation_type?: string|null}>  $linhas
     */
    public function criarRascunho(
        Empresa $empresa,
        Cliente $cliente,
        array $linhas,
        ?string $observacoes = null,
    ): Fatura;

    /**
     * Cria um rascunho de FATURA DE ADIANTAMENTO (FA); emite-se com emitirFatura().
     *
     * @param  array<int, array<string, mixed>>  $linhas  mesmo formato de criarRascunho()
     */
    public function criarRascunhoAdiantamento(
        Empresa $empresa,
        Cliente $cliente,
        array $linhas,
        ?string $observacoes = null,
    ): Fatura;

    /** Emite um rascunho pela sequência fiscal central. */
    public function emitirFatura(Fatura $fatura): Fatura;

    /** Emite um recibo pela sequência fiscal central. */
    public function emitirRecibo(
        int $empresaId,
        int $clienteId,
        string|int $valor,
        string $meioPagamento,
        ?int $faturaId = null,
    ): Recibo;

    /** Emite um recibo de regularização (RG) que refere a fatura regularizada. */
    public function emitirRegularizacao(
        int $empresaId,
        int $clienteId,
        string|int $valor,
        string $meioPagamento,
        int $faturaId,
    ): Recibo;

    /**
     * Abate (parte de) um adiantamento já recebido (FA) na fatura final (FT) do mesmo cliente.
     * Contabilidade interna de saldos; a chave torna o pedido idempotente.
     *
     * @return array<string, mixed> linha do abatimento
     */
    public function abaterAdiantamento(
        int $empresaId,
        int $faturaAdiantamentoId,
        int $faturaFinalId,
        string|int $valor,
        string $chave,
    ): array;

    /** Saldo da fatura em cêntimos inteiros. */
    public function saldoEmCentimos(Fatura $fatura): int;

    /** @return array{recebido: int, creditado: int, abatido: int, saldo: int} valores em cêntimos */
    public function resumoDaFatura(Fatura $fatura): array;
}
