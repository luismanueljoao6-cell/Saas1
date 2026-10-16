<?php

namespace Modules\Faturacao\Tests\Unit;

use InvalidArgumentException;
use Modules\Faturacao\Support\QrCodeAgt;
use Modules\Faturacao\Support\QrEncoder;
use PHPUnit\Framework\TestCase;

class QrCodeAgtTest extends TestCase
{
    private const URL_EXEMPLO = 'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor=5001636863&document=FT%20FT6325S2C/10006';

    public function test_url_segue_a_especificacao_da_agt_com_espacos_codificados(): void
    {
        $this->assertSame(self::URL_EXEMPLO, QrCodeAgt::url('5001636863', 'FT FT6325S2C/10006'));
    }

    public function test_matriz_coincide_com_a_implementacao_de_referencia(): void
    {
        // Versão 7 (45x45), correção M, máscara 0: hash dos módulos gerado pela
        // biblioteca python-qrcode e confirmado módulo a módulo contra este codificador.
        $matriz = QrEncoder::matriz(self::URL_EXEMPLO, 0);
        $bits = '';
        foreach ($matriz as $linha) {
            foreach ($linha as $escuro) {
                $bits .= $escuro ? '1' : '0';
            }
        }

        $this->assertCount(45, $matriz);
        $this->assertSame('1885fc953c8d7a3907ca1b434c56cd0092a8e882ddf9afd30cf8bdac568e937b', hash('sha256', $bits));
    }

    public function test_versao_automatica_e_a_menor_que_comporta_o_texto(): void
    {
        $this->assertCount(21, QrEncoder::matriz(str_repeat('a', 14))); // versão 1
        $this->assertCount(25, QrEncoder::matriz(str_repeat('a', 15))); // versão 2
        $this->assertCount(45, QrEncoder::matriz(self::URL_EXEMPLO));   // versão 7
        $this->assertCount(57, QrEncoder::matriz(str_repeat('a', 213))); // versão 10
    }

    public function test_texto_demasiado_longo_e_recusado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrEncoder::matriz(str_repeat('a', 214));
    }

    public function test_png_tem_350_por_350_pixeis_sem_depender_de_gd(): void
    {
        $png = QrCodeAgt::png(self::URL_EXEMPLO);
        [$largura, $altura, $tipo] = getimagesizefromstring($png);

        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $this->assertSame([350, 350, IMAGETYPE_PNG], [$largura, $altura, $tipo]);
    }

    public function test_dados_do_png_tem_o_tamanho_esperado(): void
    {
        if (! function_exists('gzuncompress')) {
            $this->markTestSkipped('Extensão zlib não disponível para validar o fluxo do PNG.');
        }

        $png = QrCodeAgt::png(self::URL_EXEMPLO);
        $inicio = strpos($png, 'IDAT') + 4;
        $comprimento = unpack('N', substr($png, $inicio - 8, 4))[1];
        $linhas = gzuncompress(substr($png, $inicio, $comprimento));

        $this->assertSame(350 * (1 + 44), strlen($linhas)); // filtro + 44 bytes por linha
    }

    public function test_svg_e_data_uri(): void
    {
        $this->assertStringStartsWith('<svg ', QrCodeAgt::svg(self::URL_EXEMPLO));
        $this->assertStringStartsWith('data:image/png;base64,', QrCodeAgt::dataUri(self::URL_EXEMPLO));
    }
}
