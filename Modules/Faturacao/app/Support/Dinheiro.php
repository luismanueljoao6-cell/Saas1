<?php

namespace Modules\Faturacao\Support;

/**
 * Aritmética monetária exata: tudo em inteiros (cêntimos / milésimos),
 * sem passar por float na conversão (o texto decimal é lido dígito a
 * dígito). Valores NÃO negativos. Arredondamento "half up" nos valores base e
 * POR EXCESSO no imposto: a AGT exige que taxContribution seja arredondado
 * por excesso ao cêntimo (ex.: 23.144 => 23.15).
 */
final class Dinheiro
{
    /** Máx. de quantidade × preço (milésimos × cêntimos) para não haver overflow de int. */
    private const LIMITE_LINHA = 900_000_000_000_000_000;

    public static function centimos(mixed $valor): int
    {
        return self::escalar($valor, 2);
    }

    /**
     * Percentagem com 2 casas decimais em pontos base (33,33% → 3333; 40% → 4000),
     * lida dígito a dígito: nunca multiplica um float por 100.
     */
    public static function pontosBase(mixed $percentagem): int
    {
        return self::escalar($percentagem, 2);
    }

    /** Converte uma quantidade decimal em milésimos sem passar por float. */
    public static function milesimos(mixed $valor): int
    {
        return self::escalar($valor, 3);
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
    public static function calcularLinha(mixed $quantidade, mixed $precoUnitario, mixed $taxaIva): array
    {
        $milesimos = self::escalar($quantidade, 3);
        $preco = self::centimos($precoUnitario);
        $taxa = self::escalar($taxaIva, 2); // 14.00 -> 1400

        if ($milesimos <= 0) {
            throw new \InvalidArgumentException('A quantidade tem de ser positiva.');
        }

        if ($preco < 0) {
            throw new \InvalidArgumentException('O preço unitário não pode ser negativo.');
        }

        if ($taxa < 0 || $taxa > 10000) {
            throw new \InvalidArgumentException('A taxa de IVA tem de estar entre 0 e 100.');
        }

        if ($preco > 0 && $milesimos > intdiv(self::LIMITE_LINHA, $preco)) {
            throw new \InvalidArgumentException('O valor da linha excede o limite permitido.');
        }

        $semIva = intdiv($milesimos * $preco + 500, 1000);
        $iva = intdiv($semIva * $taxa + 9999, 10000); // por excesso (AGT)

        return [
            'sem_iva' => self::formatar($semIva),
            'iva' => self::formatar($iva),
            'total' => self::formatar($semIva + $iva),
        ];
    }

    /**
     * Calcula ceil(($a * $b) / $divisor) sem permitir overflow silencioso.
     * Reduz fatores comuns antes da multiplicação; se o produto continuar
     * fora do intervalo inteiro suportado pelo PHP, rejeita a operação.
     */
    public static function multiplicarDividirPorExcesso(int $a, int $b, int $divisor): int
    {
        if ($a < 0 || $b < 0 || $divisor <= 0) {
            throw new \InvalidArgumentException('Os valores do cálculo proporcional têm de ser não negativos e o divisor positivo.');
        }

        if ($a === 0 || $b === 0) {
            return 0;
        }

        $parteInteira = intdiv($a, $divisor);

        if ($parteInteira > intdiv(PHP_INT_MAX, $b)) {
            throw new \InvalidArgumentException('O cálculo proporcional excede o limite inteiro seguro.');
        }

        $resultado = $parteInteira * $b;
        $parteFracionaria = $a % $divisor;
        [$quociente, $resto] = self::multiplicarModuloComQuociente($parteFracionaria, $b, $divisor);

        if ($resultado > PHP_INT_MAX - $quociente) {
            throw new \InvalidArgumentException('O cálculo proporcional excede o limite inteiro seguro.');
        }

        $resultado += $quociente;

        if ($resto !== 0) {
            if ($resultado === PHP_INT_MAX) {
                throw new \InvalidArgumentException('O arredondamento proporcional excede o limite inteiro seguro.');
            }

            $resultado++;
        }

        return $resultado;
    }

    /**
     * Devolve quociente e resto de ($a * $b) / $divisor, sem construir o produto.
     *
     * @return array{0: int, 1: int}
     */
    private static function multiplicarModuloComQuociente(int $a, int $b, int $divisor): array
    {
        $quociente = 0;
        $resto = 0;

        foreach (str_split(decbin($b)) as $bit) {
            // Duplica o valor acumulado sem deixar o resto ultrapassar o divisor.
            $carry = 0;

            if ($resto >= $divisor - $resto) {
                $resto -= $divisor - $resto;
                $carry = 1;
            } else {
                $resto += $resto;
            }

            // Soma 'a' quando o bit atual é 1, novamente sem overflow.
            if ($bit === '1' && $a !== 0) {
                if ($resto >= $divisor - $a) {
                    $resto -= $divisor - $a;
                    $carry++;
                } else {
                    $resto += $a;
                }
            }

            if ($quociente > intdiv(PHP_INT_MAX - $carry, 2)) {
                throw new \InvalidArgumentException('O cálculo proporcional excede o limite inteiro seguro.');
            }

            $quociente = ($quociente * 2) + $carry;
        }

        return [$quociente, $resto];
    }

    /** Converte um decimal em inteiro com $casas casas, half-up, sem float. */
    private static function escalar(mixed $valor, int $casas): int
    {
        if (is_float($valor)) {
            throw new \InvalidArgumentException('Valores monetários devem ser enviados como string decimal ou inteiro; float não é permitido.');
        }

        if ($valor === null || $valor === '') {
            return 0;
        }

        $texto = trim((string) $valor);

        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $texto, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException("Valor numérico inválido: '{$texto}'.");
        }

        if (strlen(ltrim($m[2], '0')) > 12) {
            throw new \InvalidArgumentException('Valor numérico demasiado grande.');
        }

        $fracao = str_pad($m[3] ?? '', $casas + 1, '0');
        $unidades = (int) ($m[2] ?: '0') * (10 ** $casas) + (int) substr($fracao, 0, $casas);

        if ((int) $fracao[$casas] >= 5) {
            $unidades++;
        }

        return $m[1] === '-' ? -$unidades : $unidades;
    }
}
