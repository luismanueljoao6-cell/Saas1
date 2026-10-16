<?php

namespace Modules\Faturacao\Tests\Unit;

use Modules\Faturacao\Support\QrCodeAgt;
use PHPUnit\Framework\TestCase;

class QrCodeAgtTest extends TestCase
{
    public function test_url_segue_a_especificacao_da_agt_com_espacos_codificados(): void
    {
        $this->assertSame(
            'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor=5001636863&document=FT%20FT6325S2C/10006',
            QrCodeAgt::url('5001636863', 'FT FT6325S2C/10006'),
        );
    }

    public function test_png_tem_350_por_350_pixeis(): void
    {
        if (! QrCodeAgt::disponivel() || ! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('bacon/qr-code ou a extensão GD não estão disponíveis.');
        }

        $png = QrCodeAgt::png(QrCodeAgt::url('5001636863', 'FT FT6325S2C/10006'));
        [$largura, $altura, $tipo] = getimagesizefromstring((string) $png);

        $this->assertSame([350, 350, IMAGETYPE_PNG], [$largura, $altura, $tipo]);
    }

    public function test_svg_e_data_uri(): void
    {
        if (! QrCodeAgt::disponivel()) {
            $this->markTestSkipped('bacon/qr-code não está instalado.');
        }

        $url = QrCodeAgt::url('5001636863', 'FT FT6325S2C/10006');

        $this->assertStringStartsWith('<svg ', QrCodeAgt::svg($url));
        $this->assertMatchesRegularExpression('#^data:image/(png|svg\+xml);base64,#', QrCodeAgt::dataUri($url));
    }
}
