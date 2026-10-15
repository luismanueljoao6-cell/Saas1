<?php

namespace Modules\EstudioMusica\Support;

/**
 * Normalização de números de telefone para o formato internacional,
 * assumindo Angola (+244) quando o número vem sem indicativo (ex.:
 * "923 456 789" — o formato em que os clientes costumam ser registados).
 */
class NumeroTelefone
{
    /**
     * @return string|null só dígitos, com indicativo (ex.: 244923456789); null se inválido
     */
    public static function internacional(?string $numero): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $numero) ?? '';

        if (str_starts_with($digitos, '00')) {
            $digitos = substr($digitos, 2);
        } else {
            $digitos = ltrim($digitos, '0');
        }

        if (strlen($digitos) === 9) {
            return '244'.$digitos;
        }

        if (strlen($digitos) >= 10 && strlen($digitos) <= 15) {
            return $digitos;
        }

        return null;
    }

    /** Para logs: nunca registar o número completo. */
    public static function mascarar(string $numero): string
    {
        $digitos = preg_replace('/\D/', '', $numero) ?? '';

        return strlen($digitos) > 3 ? str_repeat('*', strlen($digitos) - 3).substr($digitos, -3) : '***';
    }
}
