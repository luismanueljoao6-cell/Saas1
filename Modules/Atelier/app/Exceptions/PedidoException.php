<?php

namespace Modules\Atelier\Exceptions;

use Exception;

/**
 * Mesma ideia de AssinaturaFiscalException: uma exceção de domínio com
 * fábricas estáticas nomeadas, para que quem apanha a exceção (controller,
 * job) saiba exatamente que erro de negócio aconteceu sem parsear mensagens.
 */
class PedidoException extends Exception
{
    public static function transicaoInvalida(string $de, string $para): self
    {
        return new self("Não é possível mudar o pedido de \"{$de}\" para \"{$para}\".");
    }

    public static function sinalJaRegistado(): self
    {
        return new self('Este pedido já tem um sinal/adiantamento registado.');
    }

    public static function faturaFinalJaGerada(): self
    {
        return new self('Este pedido já tem uma fatura final gerada.');
    }

    public static function clienteSemDadosFaturacao(): self
    {
        return new self('Este cliente não tem NIF/dados suficientes para faturação — verifica a ficha do cliente no módulo Faturação.');
    }

    public static function valorInvalido(): self
    {
        return new self('O valor indicado tem de ser maior que zero.');
    }
}
