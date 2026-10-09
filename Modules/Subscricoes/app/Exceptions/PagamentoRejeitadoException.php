<?php

namespace Modules\Subscricoes\Exceptions;

use Modules\Subscricoes\Models\Pagamento;
use RuntimeException;

/**
 * Pagamento recebido do gateway que NÃO deve ativar a subscrição (valor em
 * falta/insuficiente, estado inválido). Não faz sentido tentar de novo:
 * o Job marca-o como falhado e fica para revisão manual.
 */
class PagamentoRejeitadoException extends RuntimeException
{
    public static function estadoInvalido(Pagamento $pagamento): self
    {
        return new self("Pagamento {$pagamento->id} no estado '{$pagamento->estado}' não pode ser confirmado.");
    }

    public static function valorAusente(Pagamento $pagamento): self
    {
        return new self("Notificação do pagamento {$pagamento->id} sem valor pago no payload — confirmação recusada.");
    }

    public static function valorDivergente(Pagamento $pagamento, float $valorPago): self
    {
        return new self("Pagamento {$pagamento->id}: valor pago ({$valorPago}) inferior ao esperado ({$pagamento->valor}).");
    }
}
