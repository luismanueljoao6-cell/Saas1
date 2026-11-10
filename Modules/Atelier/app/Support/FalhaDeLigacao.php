<?php

namespace Modules\Atelier\Support;

use Throwable;

/**
 * Distingue uma ligação que falhou ANTES de o pedido sair (seguro repetir)
 * de uma que falhou depois (o fornecedor pode já ter recebido a mensagem).
 * Na dúvida (código cURL desconhecido ou ausente) assume-se que o resultado
 * é indeterminado: é preferível pedir uma decisão a duplicar uma mensagem.
 */
final class FalhaDeLigacao
{
    /** DNS (6), ligação recusada (7), handshake TLS (35) e certificado (60). */
    private const ANTES_DO_ENVIO = [6, 7, 35, 60];

    public static function foiAntesDoEnvio(Throwable $erro): bool
    {
        if (preg_match('/cURL error (\d+)/', $erro->getMessage(), $m) !== 1) {
            return false;
        }

        return in_array((int) $m[1], self::ANTES_DO_ENVIO, true);
    }
}
