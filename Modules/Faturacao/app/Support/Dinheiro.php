<?php

namespace Modules\Faturacao\Support;

/**
 * Aritmética monetária exata: tudo em inteiros (cêntimos / milésimos),
 * nunca em float. Valores NÃO negativos; arredondamento "half up".
 */
final class Dinheiro
{
    public static function centimos(string|int|float|null $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    public static function formatar(int $centimos): string
    {
        $sinal = $centimos < 0 ? '-' : '';
        $abs = abs($centimos);

        return sprintf('%s%d.%02d', $sinal, intdiv($abs, 100), $abs % 100);
    }

    /**
     * @return array{sem_iva: string, iva: string, total: string}
     */
    public static function calcularLinha(string|int|float $quantidade, string|int|float $precoUnitario, string|int|float $taxaIva): array
    {
        $milesimos = (int) round(((float) $quantidade) * 1000);
        $preco = self::centimos($precoUnitario);
        $taxa = (int) round(((float) $taxaIva) * 100); // 14.00 -> 1400

        $semIva = intdiv($milesimos * $preco + 500, 1000);
        $iva = intdiv($semIva * $taxa + 5000, 10000);

        return [
            'sem_iva' => self::formatar($semIva),
            'iva' => self::formatar($iva),
            'total' => self::formatar($semIva + $iva),
        ];
    }
}
