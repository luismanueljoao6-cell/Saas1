<?php

namespace Modules\Subscricoes\Exceptions;

use Exception;

class GatewayPagamentoException extends Exception
{
    public static function falhaAoGerarReferencia(string $gateway, string $motivo): self
    {
        return new self("Falha ao gerar referência de pagamento via {$gateway}: {$motivo}");
    }

    public static function webhookInvalido(string $gateway): self
    {
        return new self("Pedido de webhook do gateway {$gateway} falhou a validação de autenticidade.");
    }

    public static function pagamentoNaoEncontrado(string $referenciaExterna): self
    {
        return new self("Nenhum pagamento encontrado para a referência externa '{$referenciaExterna}'.");
    }
}
