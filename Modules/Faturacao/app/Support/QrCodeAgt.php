<?php

namespace Modules\Faturacao\Support;

/**
 * QR Code dos documentos impressos, segundo as especificações da AGT: URL
 * https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor={NIF}&document={documentNo}
 * (cada espaço do documentNo => %20), modelo 2, correção de erros M, modo byte
 * UTF-8, imagem PNG de 350x350 px.
 *
 * A AGT indica "versão 4 (33x33)", mas o URL tem cerca de 120 bytes e a versão 4
 * com correção M só comporta 62: usa-se a menor versão que o comporta.
 *
 * Sem dependências: o QR é gerado por QrEncoder e o PNG é escrito à mão (sem GD
 * nem zlib), por isso funciona em qualquer instalação de PHP.
 */
final class QrCodeAgt
{
    public const BASE = 'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe';

    public const TAMANHO = 350;

    private const MARGEM = 4; // módulos de zona de silêncio

    public static function url(string $nifEmissor, string $numeroDocumento): string
    {
        return self::BASE.'?emissor='.$nifEmissor.'&document='.str_replace(' ', '%20', $numeroDocumento);
    }

    /** PNG 350x350, preto e branco (1 bit por píxel). */
    public static function png(string $url, int $tamanho = self::TAMANHO): string
    {
        [$matriz, $modulo, $deslocamento] = self::geometria($url, $tamanho);
        $n = count($matriz);

        $bytesPorLinha = intdiv($tamanho + 7, 8);
        $brancaLinha = str_repeat("\xFF", $bytesPorLinha);
        $linhasModulo = [];

        // Uma linha de píxeis por linha de módulos (1 = branco, 0 = preto).
        foreach ($matriz as $linhaModulos) {
            $bits = array_fill(0, $bytesPorLinha * 8, 1);

            foreach ($linhaModulos as $x => $escuro) {
                if ($escuro) {
                    $inicio = $deslocamento + $x * $modulo;
                    for ($p = 0; $p < $modulo; $p++) {
                        $bits[$inicio + $p] = 0;
                    }
                }
            }

            $linha = '';
            foreach (array_chunk($bits, 8) as $grupo) {
                $linha .= chr(bindec(implode('', $grupo)));
            }
            $linhasModulo[] = $linha;
        }

        $dados = '';
        for ($y = 0; $y < $tamanho; $y++) {
            $relativo = $y - $deslocamento;
            $indice = $relativo >= 0 ? intdiv($relativo, $modulo) : -1;
            $dados .= "\x00".($indice >= 0 && $indice < $n ? $linhasModulo[$indice] : $brancaLinha);
        }

        return "\x89PNG\r\n\x1a\n"
            .self::bloco('IHDR', pack('NNCCCCC', $tamanho, $tamanho, 1, 0, 0, 0, 0))
            .self::bloco('IDAT', self::zlibSemCompressao($dados))
            .self::bloco('IEND', '');
    }

    public static function svg(string $url, int $tamanho = self::TAMANHO): string
    {
        [$matriz] = self::geometria($url, $tamanho);
        $n = count($matriz);
        $total = $n + 2 * self::MARGEM;
        $caminho = '';

        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if (! $matriz[$y][$x]) {
                    $x++;

                    continue;
                }

                $inicio = $x;
                while ($x < $n && $matriz[$y][$x]) {
                    $x++;
                }

                $caminho .= 'M'.($inicio + self::MARGEM).' '.($y + self::MARGEM).'h'.($x - $inicio).'v1h-'.($x - $inicio).'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$tamanho.'" height="'.$tamanho.'" viewBox="0 0 '.$total.' '.$total.'" shape-rendering="crispEdges">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="#fff"/><path fill="#000" d="'.$caminho.'"/></svg>';
    }

    /** data: URI do PNG, para <img src>. */
    public static function dataUri(string $url): string
    {
        return 'data:image/png;base64,'.base64_encode(self::png($url));
    }

    /** @return array{0: array<int, array<int, bool>>, 1: int, 2: int} matriz, píxeis por módulo, deslocamento */
    private static function geometria(string $url, int $tamanho): array
    {
        $matriz = QrEncoder::matriz($url);
        $total = count($matriz) + 2 * self::MARGEM;
        $modulo = intdiv($tamanho, $total);

        if ($modulo < 1) {
            throw new \RuntimeException('Tamanho do QR Code demasiado pequeno.');
        }

        return [$matriz, $modulo, intdiv($tamanho - $modulo * count($matriz), 2)];
    }

    /** Fluxo zlib com blocos "stored" (sem compressão): dispensa a extensão zlib. */
    private static function zlibSemCompressao(string $dados): string
    {
        $saida = "\x78\x01";
        $total = strlen($dados);
        $posicao = 0;

        do {
            $bloco = substr($dados, $posicao, 65535);
            $posicao += strlen($bloco);
            $ultimo = $posicao >= $total ? 1 : 0;
            $saida .= chr($ultimo).pack('vv', strlen($bloco), strlen($bloco) ^ 0xFFFF).$bloco;
        } while ($posicao < $total);

        $a = 1;
        $b = 0;
        for ($i = 0; $i < $total; $i++) {
            $a = ($a + ord($dados[$i])) % 65521;
            $b = ($b + $a) % 65521;
        }

        return $saida.pack('N', ($b << 16) | $a);
    }

    private static function bloco(string $tipo, string $dados): string
    {
        return pack('N', strlen($dados)).$tipo.$dados.pack('N', crc32($tipo.$dados));
    }
}
