<?php

namespace Modules\\Armazem\\Support;

use InvalidArgumentException;

/**
 * Operações exatas para quantidades armazenadas com três casas decimais.
 *
 * Não converter estes valores para float: o limite DECIMAL(14, 3) excede
 * a precisão decimal garantida por um double e pode alterar alertas/exportações.
 */
final class QuantidadeDecimal
{
    public static function comparar(string $primeiro, string $segundo): int
    {
        return self::unidadesAssinadas($primeiro) <=> self::unidadesAssinadas($segundo);
    }

    public static function formatar(
        string $valor,
        string $separadorDecimal = '.',
        string $separadorMilhar = '',
    ): string {
        $unidades = self::unidadesAssinadas($valor);
        $sinal = $unidades < 0 ? '-' : '';
        $absoluto = abs($unidades);
        $inteiro = (string) intdiv($absoluto, 1000);

        if ($separadorMilhar !== '') {
            $inteiro = preg_replace('/\\B(?=(\\d{3})+(?!\\d))/', $separadorMilhar, $inteiro);
        }

        return $sinal.$inteiro.$separadorDecimal.sprintf('%03d', $absoluto % 1000);
    }

    private static function unidadesAssinadas(string $valor): int
    {
        $valor = trim($valor);

        if (preg_match('/\\A(-?)(0|[1-9][0-9]{0,10})(?:\\.([0-9]{1,3}))?\\z/D', $valor, $partes) !== 1) {
            throw new InvalidArgumentException('Quantidade decimal inválida. Usa até 11 algarismos inteiros e três casas decimais.');
        }

        $unidades = ((int) $partes[2] * 1000) + (int) str_pad($partes[3] ?? '', 3, '0');

        return ($partes[1] ?? '') === '-' ? -$unidades : $unidades;
    }
}
