<?php

namespace Modules\Faturacao\Support;

use InvalidArgumentException;

/**
 * Codificador de QR Code em PHP puro (ISO/IEC 18004, modelo 2): modo byte,
 * correção de erros M, versões 1 a 10 (até 213 bytes), com escolha automática
 * da máscara. Sem dependências, sem GD e sem zlib — existe para o QR Code das
 * facturas da AGT não depender de pacotes externos nem de extensões do PHP.
 */
final class QrEncoder
{
    /** Capacidade em palavras de código por versão (correção M): [dados, EC por bloco, [blocos, dados por bloco] ...]. */
    private const BLOCOS_M = [
        1 => [16, 10, [[1, 16]]],
        2 => [28, 16, [[1, 28]]],
        3 => [44, 26, [[1, 44]]],
        4 => [64, 18, [[2, 32]]],
        5 => [86, 24, [[2, 43]]],
        6 => [108, 16, [[4, 27]]],
        7 => [124, 18, [[4, 31]]],
        8 => [154, 22, [[2, 38], [2, 39]]],
        9 => [182, 22, [[3, 36], [2, 37]]],
        10 => [216, 26, [[4, 43], [1, 44]]],
    ];

    private const ALINHAMENTO = [
        2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var array<int, int>|null */
    private static ?array $exp = null;

    /** @var array<int, int>|null */
    private static ?array $log = null;

    /**
     * @param  int|null  $mascara  0..7 para forçar (testes); null escolhe a de menor penalização
     * @param  int|null  $versao  1..10 para forçar (testes); null escolhe a menor que comporta os dados
     * @return array<int, array<int, bool>> matriz[linha][coluna], true = módulo escuro
     */
    public static function matriz(string $dados, ?int $mascara = null, ?int $versao = null): array
    {
        $versao ??= self::menorVersao(strlen($dados));

        if (! isset(self::BLOCOS_M[$versao]) || strlen($dados) > self::capacidade($versao)) {
            throw new InvalidArgumentException('Texto demasiado longo para o QR Code (máximo '.self::capacidade(10).' bytes).');
        }

        $palavras = self::palavrasFinais($dados, $versao);
        $tamanho = 17 + 4 * $versao;

        [$base, $funcao] = self::desenharFuncoes($versao, $tamanho);
        self::colocarDados($base, $funcao, $palavras, $tamanho);

        if ($mascara !== null) {
            return self::comMascara($base, $funcao, $tamanho, $mascara);
        }

        $melhor = null;
        $melhorPenalizacao = PHP_INT_MAX;

        for ($m = 0; $m < 8; $m++) {
            $candidata = self::comMascara($base, $funcao, $tamanho, $m);
            $penalizacao = self::penalizacao($candidata, $tamanho);

            if ($penalizacao < $melhorPenalizacao) {
                $melhorPenalizacao = $penalizacao;
                $melhor = $candidata;
            }
        }

        return $melhor;
    }

    public static function capacidade(int $versao): int
    {
        $bitsDados = self::BLOCOS_M[$versao][0] * 8;
        $bitsContagem = $versao >= 10 ? 16 : 8;

        return intdiv($bitsDados - 4 - $bitsContagem, 8);
    }

    private static function menorVersao(int $bytes): int
    {
        foreach (array_keys(self::BLOCOS_M) as $versao) {
            if ($bytes <= self::capacidade($versao)) {
                return $versao;
            }
        }

        throw new InvalidArgumentException('Texto demasiado longo para o QR Code (máximo '.self::capacidade(10).' bytes).');
    }

    /** @return array<int, int> palavras de código já intercaladas (dados + correção de erros) */
    private static function palavrasFinais(string $dados, int $versao): array
    {
        [$totalDados, $ecPorBloco, $grupos] = self::BLOCOS_M[$versao];

        // Fluxo de bits: modo byte (0100), contagem, dados, terminador, preenchimento.
        $bits = '0100'.str_pad(decbin(strlen($dados)), $versao >= 10 ? 16 : 8, '0', STR_PAD_LEFT);
        foreach (str_split($dados) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $bits .= str_repeat('0', min(4, $totalDados * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $palavras = array_map('bindec', str_split($bits, 8));
        for ($i = 0; count($palavras) < $totalDados; $i++) {
            $palavras[] = $i % 2 === 0 ? 0xEC : 0x11;
        }

        // Divide em blocos, calcula Reed-Solomon de cada um e intercala.
        $blocosDados = [];
        $posicao = 0;
        foreach ($grupos as [$quantidade, $tamanhoBloco]) {
            for ($i = 0; $i < $quantidade; $i++) {
                $blocosDados[] = array_slice($palavras, $posicao, $tamanhoBloco);
                $posicao += $tamanhoBloco;
            }
        }

        $blocosEc = array_map(fn (array $bloco) => self::reedSolomon($bloco, $ecPorBloco), $blocosDados);
        $resultado = [];
        $maximo = max(array_map('count', $blocosDados));

        for ($i = 0; $i < $maximo; $i++) {
            foreach ($blocosDados as $bloco) {
                if (isset($bloco[$i])) {
                    $resultado[] = $bloco[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPorBloco; $i++) {
            foreach ($blocosEc as $bloco) {
                $resultado[] = $bloco[$i];
            }
        }

        return $resultado;
    }

    /**
     * @param  array<int, int>  $dados
     * @return array<int, int>
     */
    private static function reedSolomon(array $dados, int $grau): array
    {
        self::iniciarCorpo();

        // Polinómio gerador: produto de (x - alfa^i), i = 0..grau-1.
        $gerador = [1];
        for ($i = 0; $i < $grau; $i++) {
            $novo = array_fill(0, count($gerador) + 1, 0);
            foreach ($gerador as $j => $coeficiente) {
                $novo[$j] ^= $coeficiente;
                $novo[$j + 1] ^= self::multiplicar($coeficiente, self::$exp[$i]);
            }
            $gerador = $novo;
        }

        $resto = array_fill(0, $grau, 0);
        foreach ($dados as $byte) {
            $fator = $byte ^ array_shift($resto);
            $resto[] = 0;

            for ($i = 0; $i < $grau; $i++) {
                $resto[$i] ^= self::multiplicar($gerador[$i + 1], $fator);
            }
        }

        return $resto;
    }

    private static function iniciarCorpo(): void
    {
        if (self::$exp !== null) {
            return;
        }

        $exp = [];
        $log = array_fill(0, 256, 0);
        $x = 1;

        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;

            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        self::$exp = $exp;
        self::$log = $log;
    }

    private static function multiplicar(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /**
     * Padrões de função (localizadores, alinhamento, sincronismo, informação de
     * versão) e reserva das zonas de formato. Devolve [matriz, mapa de função].
     *
     * @return array{0: array<int, array<int, bool>>, 1: array<int, array<int, bool>>}
     */
    private static function desenharFuncoes(int $versao, int $tamanho): array
    {
        $modulos = array_fill(0, $tamanho, array_fill(0, $tamanho, false));
        $funcao = array_fill(0, $tamanho, array_fill(0, $tamanho, false));

        $definir = function (int $x, int $y, bool $escuro) use (&$modulos, &$funcao, $tamanho): void {
            if ($x >= 0 && $x < $tamanho && $y >= 0 && $y < $tamanho) {
                $modulos[$y][$x] = $escuro;
                $funcao[$y][$x] = true;
            }
        };

        for ($i = 0; $i < $tamanho; $i++) {
            $definir(6, $i, $i % 2 === 0);
            $definir($i, 6, $i % 2 === 0);
        }

        foreach ([[3, 3], [$tamanho - 4, 3], [3, $tamanho - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $distancia = max(abs($dx), abs($dy));
                    $definir($cx + $dx, $cy + $dy, $distancia !== 2 && $distancia !== 4);
                }
            }
        }

        $centros = self::ALINHAMENTO[$versao] ?? [];
        $ultimo = count($centros) - 1;

        foreach ($centros as $i => $cy) {
            foreach ($centros as $j => $cx) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $ultimo) || ($i === $ultimo && $j === 0)) {
                    continue; // sobreposto aos localizadores
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $definir($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        // Zonas de formato (preenchidas depois da máscara) e módulo escuro fixo.
        self::desenharFormato($modulos, $funcao, $tamanho, 0);
        $definir(8, $tamanho - 8, true);

        if ($versao >= 7) {
            $resto = $versao;
            for ($i = 0; $i < 12; $i++) {
                $resto = ($resto << 1) ^ (($resto >> 11) * 0x1F25);
            }
            $bits = ($versao << 12) | $resto;

            for ($i = 0; $i < 18; $i++) {
                $escuro = (($bits >> $i) & 1) === 1;
                $a = $tamanho - 11 + $i % 3;
                $b = intdiv($i, 3);
                $definir($a, $b, $escuro);
                $definir($b, $a, $escuro);
            }
        }

        return [$modulos, $funcao];
    }

    /**
     * @param  array<int, array<int, bool>>  $modulos
     * @param  array<int, array<int, bool>>  $funcao
     */
    private static function desenharFormato(array &$modulos, array &$funcao, int $tamanho, int $mascara): void
    {
        $dados = (0 << 3) | $mascara; // correção M = 00
        $resto = $dados;
        for ($i = 0; $i < 10; $i++) {
            $resto = ($resto << 1) ^ (($resto >> 9) * 0x537);
        }
        $bits = (($dados << 10) | $resto) ^ 0x5412;

        $definir = function (int $x, int $y, bool $escuro) use (&$modulos, &$funcao): void {
            $modulos[$y][$x] = $escuro;
            $funcao[$y][$x] = true;
        };
        $bit = fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $definir(8, $i, $bit($i));
        }
        $definir(8, 7, $bit(6));
        $definir(8, 8, $bit(7));
        $definir(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $definir(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $definir($tamanho - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $definir(8, $tamanho - 15 + $i, $bit($i));
        }
    }

    /**
     * @param  array<int, array<int, bool>>  $modulos
     * @param  array<int, array<int, bool>>  $funcao
     * @param  array<int, int>  $palavras
     */
    private static function colocarDados(array &$modulos, array $funcao, array $palavras, int $tamanho): void
    {
        $indice = 0;
        $total = count($palavras) * 8;

        for ($direita = $tamanho - 1; $direita >= 1; $direita -= 2) {
            if ($direita === 6) {
                $direita = 5;
            }

            for ($vertical = 0; $vertical < $tamanho; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $direita - $j;
                    $paraCima = (($direita + 1) & 2) === 0;
                    $y = $paraCima ? $tamanho - 1 - $vertical : $vertical;

                    if (! $funcao[$y][$x] && $indice < $total) {
                        $modulos[$y][$x] = ((($palavras[$indice >> 3] >> (7 - ($indice & 7))) & 1) === 1);
                        $indice++;
                    }
                }
            }
        }
    }

    /**
     * @param  array<int, array<int, bool>>  $base
     * @param  array<int, array<int, bool>>  $funcao
     * @return array<int, array<int, bool>>
     */
    private static function comMascara(array $base, array $funcao, int $tamanho, int $mascara): array
    {
        $modulos = $base;

        for ($y = 0; $y < $tamanho; $y++) {
            for ($x = 0; $x < $tamanho; $x++) {
                if ($funcao[$y][$x]) {
                    continue;
                }

                $inverter = match ($mascara) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                if ($inverter) {
                    $modulos[$y][$x] = ! $modulos[$y][$x];
                }
            }
        }

        $funcaoFormato = $funcao;
        self::desenharFormato($modulos, $funcaoFormato, $tamanho, $mascara);

        return $modulos;
    }

    /** @param  array<int, array<int, bool>>  $m */
    private static function penalizacao(array $m, int $tamanho): int
    {
        $resultado = 0;

        for ($eixo = 0; $eixo < 2; $eixo++) {
            for ($a = 0; $a < $tamanho; $a++) {
                $cor = false;
                $corrida = 0;
                $historico = array_fill(0, 7, 0);

                for ($b = 0; $b < $tamanho; $b++) {
                    $valor = $eixo === 0 ? $m[$a][$b] : $m[$b][$a];

                    if ($valor === $cor) {
                        $corrida++;
                        if ($corrida === 5) {
                            $resultado += 3;
                        } elseif ($corrida > 5) {
                            $resultado++;
                        }
                    } else {
                        self::historico($corrida, $historico, $tamanho);
                        if (! $cor) {
                            $resultado += self::padroes($historico) * 40;
                        }
                        $cor = $valor;
                        $corrida = 1;
                    }
                }

                if ($cor) {
                    self::historico($corrida, $historico, $tamanho);
                    $corrida = 0;
                }
                $corrida += $tamanho;
                self::historico($corrida, $historico, $tamanho);
                $resultado += self::padroes($historico) * 40;
            }
        }

        for ($y = 0; $y < $tamanho - 1; $y++) {
            for ($x = 0; $x < $tamanho - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $resultado += 3;
                }
            }
        }

        $escuros = 0;
        foreach ($m as $linha) {
            $escuros += count(array_filter($linha));
        }
        $total = $tamanho * $tamanho;
        $k = intdiv(abs($escuros * 20 - $total * 10) + $total - 1, $total) - 1;

        return $resultado + $k * 10;
    }

    /** @param  array<int, int>  $historico */
    private static function historico(int $comprimento, array &$historico, int $tamanho): void
    {
        if ($historico[0] === 0) {
            $comprimento += $tamanho; // margem clara inicial
        }

        array_pop($historico);
        array_unshift($historico, $comprimento);
    }

    /** @param  array<int, int>  $h */
    private static function padroes(array $h): int
    {
        $n = $h[1];
        $nucleo = $n > 0 && $h[2] === $n && $h[3] === $n * 3 && $h[4] === $n && $h[5] === $n;

        return ($nucleo && $h[0] >= $n * 4 && $h[6] >= $n ? 1 : 0)
            + ($nucleo && $h[6] >= $n * 4 && $h[0] >= $n ? 1 : 0);
    }
}
