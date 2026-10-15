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

    /** @param  string  $decimal  decimal em texto (ex.: "250.00", "0.500") */
    public static function deDecimal(string $decimal): self
    {
        $texto = trim($decimal);

        if (preg_match('/^-?\d+(\.\d+)?$/', $texto) !== 1) {
            throw new \InvalidArgumentException("Número decimal inválido: {$decimal}");
        }

        if (str_contains($texto, '.')) {
            $texto = rtrim(rtrim($texto, '0'), '.');
        }

        return new self($texto === '' || $texto === '-' ? '0' : $texto);
    }
}
