<?php

namespace Modules\Faturacao\Support;

/**
 * Aritmética monetária exata: tudo em inteiros (cêntimos / milésimos),
 * sem passar por float na conversão (o texto decimal é lido dígito a
 * dígito). Valores NÃO negativos; arredondamento "half up".
 */
final class Dinheiro
{
    public static function centimos(string|int|float|null $valor): int
    {
        return self::escalar($valor, 2);
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
        $milesimos = self::escalar($quantidade, 3);
        $preco = self::centimos($precoUnitario);
        $taxa = self::escalar($taxaIva, 2); // 14.00 -> 1400

        $semIva = intdiv($milesimos * $preco + 500, 1000);
        $iva = intdiv($semIva * $taxa + 5000, 10000);

        return [
            'sem_iva' => self::formatar($semIva),
            'iva' => self::formatar($iva),
            'total' => self::formatar($semIva + $iva),
        ];
    }

    /** Converte um decimal em inteiro com $casas casas, half-up, sem float. */
    private static function escalar(string|int|float|null $valor, int $casas): int
    {
        if ($valor === null || $valor === '') {
            return 0;
        }

        $texto = is_float($valor) ? number_format($valor, $casas + 4, '.', '') : trim((string) $valor);

        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $texto, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return (int) round(((float) $valor) * (10 ** $casas));
        }

        $fracao = str_pad($m[3] ?? '', $casas + 1, '0');
        $unidades = (int) ($m[2] ?: '0') * (10 ** $casas) + (int) substr($fracao, 0, $casas);

        if ((int) $fracao[$casas] >= 5) {
            $unidades++;
        }

        return $m[1] === '-' ? -$unidades : $unidades;
    }
}
