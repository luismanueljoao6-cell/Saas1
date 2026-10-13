<?php

namespace Modules\Faturacao\Support;

/**
 * Número já formatado para JSON canónico (sem aspas, sem zeros à direita
 * desnecessários). Existe para nunca passar valores monetários por float.
 */
final class NumeroJson
{
    private function __construct(public readonly string $literal) {}

    /** @param  int  $centimos  valor em cêntimos (ex.: 57000 => 570) */
    public static function deCentimos(int $centimos): self
    {
        $sinal = $centimos < 0 ? '-' : '';
        $abs = abs($centimos);
        $inteiro = intdiv($abs, 100);
        $fracao = $abs % 100;

        if ($fracao === 0) {
            return new self($sinal.$inteiro);
        }

        return new self($sinal.$inteiro.'.'.rtrim(sprintf('%02d', $fracao), '0'));
    }
}
