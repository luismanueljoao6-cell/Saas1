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
     * @param  array<int, array{produto_id?: int|null, descricao: string, quantidade: int|float, preco_unitario: int|float|string, preco_unitario_base?: int|float|string|null, taxa_iva: int|float|string, motivo_isencao?: string|null, operation_type?: string|null}>  $linhas
     */
    public function criarRascunho(
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
        string|int|float $valor,
        string $meioPagamento,
        ?int $faturaId = null,
    ): Recibo;

    /** Saldo da fatura em cêntimos inteiros. */
    public function saldoEmCentimos(Fatura $fatura): int;

    /** @return array{recebido: int, creditado: int, saldo: int} valores em cêntimos */
    public function resumoDaFatura(Fatura $fatura): array;
}
