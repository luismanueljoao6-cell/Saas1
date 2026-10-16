<?php

namespace Modules\EstudioMusica\Support;

use Carbon\Carbon;

/**
 * Quem nasceu a 29 de fevereiro festeja a 28 de fevereiro nos anos não
 * bissextos (antes, ficava sem mensagem e sem cupão 3 anos em cada 4).
 */
final class Aniversario
{
    public static function hoje(Carbon|string $nascimento, ?Carbon $hoje = null): bool
    {
        $nascimento = Carbon::parse($nascimento);
        $hoje ??= now();

        if ($nascimento->month === 2 && $nascimento->day === 29 && ! $hoje->isLeapYear()) {
            return $hoje->month === 2 && $hoje->day === 28;
        }

        return $nascimento->month === $hoje->month && $nascimento->day === $hoje->day;
    }
}
