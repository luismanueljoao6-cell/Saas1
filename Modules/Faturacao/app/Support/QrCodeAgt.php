<?php

namespace Modules\Faturacao\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use RuntimeException;

/**
 * QR Code dos documentos impressos, segundo as especificações da AGT: URL
 * https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor={NIF}&document={documentNo}
 * (cada espaço do documentNo => %20), modelo 2, correção de erros M, modo byte
 * UTF-8, imagem PNG de 350x350 px.
 *
 * A AGT indica "versão 4 (33x33)", mas o URL tem cerca de 120 bytes e a versão 4
 * com correção M só comporta 62: usa-se a menor versão que o comporta.
 */
final class QrCodeAgt
{
    public const BASE = 'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe';

    public const TAMANHO = 350;

    public static function url(string $nifEmissor, string $numeroDocumento): string
    {
        return self::BASE.'?emissor='.$nifEmissor.'&document='.str_replace(' ', '%20', $numeroDocumento);
    }

    /** Verdadeiro se a biblioteca bacon/qr-code está instalada (composer require bacon/qr-code). */
    public static function disponivel(): bool
    {
        return class_exists(Encoder::class);
    }

    /** PNG 350x350, ou null se a biblioteca ou a extensão GD não estiverem disponíveis. */
    public static function png(string $url, int $tamanho = self::TAMANHO): ?string
    {
        if (! self::disponivel() || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        [$matriz, $total, $modulo, $deslocamento] = self::geometria($url, $tamanho);

        $imagem = imagecreatetruecolor($tamanho, $tamanho);
        imagefill($imagem, 0, 0, (int) imagecolorallocate($imagem, 255, 255, 255));
        $preto = (int) imagecolorallocate($imagem, 0, 0, 0);
        $n = $matriz->getWidth();

        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matriz->get($x, $y) === 1) {
                    $px = $deslocamento + $x * $modulo;
                    $py = $deslocamento + $y * $modulo;
                    imagefilledrectangle($imagem, $px, $py, $px + $modulo - 1, $py + $modulo - 1, $preto);
                }
            }
        }

        ob_start();
        imagepng($imagem);
        $png = (string) ob_get_clean();
        imagedestroy($imagem);

        return $png;
    }

    public static function svg(string $url, int $tamanho = self::TAMANHO): ?string
    {
        if (! self::disponivel()) {
            return null;
        }

        [$matriz, $total] = self::geometria($url, $tamanho);
        $n = $matriz->getWidth();
        $caminho = '';

        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if ($matriz->get($x, $y) !== 1) {
                    $x++;

                    continue;
                }

                $inicio = $x;
                while ($x < $n && $matriz->get($x, $y) === 1) {
                    $x++;
                }

                $caminho .= 'M'.($inicio + 4).' '.($y + 4).'h'.($x - $inicio).'v1h-'.($x - $inicio).'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$tamanho.'" height="'.$tamanho.'" viewBox="0 0 '.$total.' '.$total.'" shape-rendering="crispEdges">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="#fff"/><path fill="#000" d="'.$caminho.'"/></svg>';
    }

    /** data: URI para <img src>; PNG quando possível, senão SVG; null sem a biblioteca. */
    public static function dataUri(string $url): ?string
    {
        $png = self::png($url);

        if ($png !== null) {
            return 'data:image/png;base64,'.base64_encode($png);
        }

        $svg = self::svg($url);

        return $svg !== null ? 'data:image/svg+xml;base64,'.base64_encode($svg) : null;
    }

    /** @return array{0: ByteMatrix, 1: int, 2: int, 3: int} matriz, módulos totais (com margem), px por módulo, deslocamento */
    protected static function geometria(string $url, int $tamanho): array
    {
        $codigo = Encoder::encode($url, ErrorCorrectionLevel::M(), 'UTF-8', null, false);
        $matriz = $codigo->getMatrix();
        $total = $matriz->getWidth() + 8; // margem de 4 módulos (quiet zone)
        $modulo = intdiv($tamanho, $total);

        if ($modulo < 1) {
            throw new RuntimeException('Tamanho do QR Code demasiado pequeno.');
        }

        $deslocamento = intdiv($tamanho - $modulo * $total, 2) + 4 * $modulo;

        return [$matriz, $total, $modulo, $deslocamento];
    }
}
