<?php

namespace Modules\Faturacao\Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\SafTExportService;
use Tests\TestCase;

/** SAF-T: estrutura provisória com TaxTable, DocumentTotals e o imposto/isenção por linha. */
class SafTEstruturaTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/saft-'.uniqid();
        mkdir($this->tmp);
        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->tmp.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmp);
        });

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($this->tmp.'/chave.pem', $pem);

        config([
            'faturacao.chave_privada_path' => $this->tmp.'/chave.pem',
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
            'faturacao.agt.nd_codigo_nao_sujeicao' => 'M02',
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja SAFT', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    protected function emitirDocumentos(): void
    {
        $faturas = app(FaturaService::class);
        $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));
        $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Livro', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0, 'motivo_isencao' => 'M13'],
        ]));
        $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Operação não sujeita', 'quantidade' => 1, 'preco_unitario' => '50.00', 'taxa_iva' => 0, 'motivo_isencao' => 'M02'],
        ]));

        $notas = app(NotaCreditoDebitoService::class);
        $notas->emitir($notas->criarRascunho($this->empresa->id, $this->cliente->id, 'debito', 'Repasse', [
            ['descricao' => 'Repasse de despesas', 'quantidade' => 1, 'preco_unitario' => '25.00', 'taxa_iva' => 0],
        ]));
    }

    protected function xml(): string
    {
        return app(SafTExportService::class)->gerar($this->empresa, now()->startOfYear(), now()->endOfYear());
    }

    protected function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        return new DOMXPath($dom);
    }

    public function test_tem_taxtable_com_os_impostos_usados_e_documenttotals_em_cada_documento(): void
    {
        $this->emitirDocumentos();
        $xp = $this->xpath($this->xml());

        $entradas = collect($xp->query('//MasterFiles/TaxTable/TaxTableEntry'))
            ->map(fn ($e) => $xp->evaluate('string(TaxType)', $e).'/'.$xp->evaluate('string(TaxCode)', $e).'/'.$xp->evaluate('string(TaxPercentage)', $e))
            ->sort()->values()->all();

        $this->assertSame(['IVA/ISE/0.00', 'IVA/NOR/14.00', 'NS/NS/0.00'], $entradas);

        $this->assertSame(4, (int) $xp->evaluate('count(//Invoice/DocumentTotals)'));
        $this->assertSame(0, (int) $xp->evaluate('count(//Invoice/GrossTotal)'), 'Os totais já não ficam soltos na fatura.');
        $this->assertSame('1140.00', $xp->evaluate('string((//Invoice/DocumentTotals/GrossTotal)[1])'));
        $this->assertSame('140.00', $xp->evaluate('string((//Invoice/DocumentTotals/TaxPayable)[1])'));
    }

    public function test_linhas_isentas_nao_sujeitas_e_notas_de_debito_levam_o_codigo_de_isencao(): void
    {
        $this->emitirDocumentos();
        $xp = $this->xpath($this->xml());

        $this->assertSame('ISE', $xp->evaluate('string(//Invoice[Line/Description="Livro"]/Line/Tax/TaxCode)'));
        $this->assertSame('M13', $xp->evaluate('string(//Invoice[Line/Description="Livro"]/Line/TaxExemptionCode)'));

        $this->assertSame('NS', $xp->evaluate('string(//Invoice[Line/Description="Operação não sujeita"]/Line/Tax/TaxType)'));
        $this->assertSame('M02', $xp->evaluate('string(//Invoice[Line/Description="Operação não sujeita"]/Line/TaxExemptionCode)'));

        $this->assertSame('ND', $xp->evaluate('string(//Invoice[Line/Description="Repasse de despesas"]/InvoiceType)'));
        $this->assertSame('NS', $xp->evaluate('string(//Invoice[Line/Description="Repasse de despesas"]/Line/Tax/TaxType)'));
        $this->assertSame('0.00', $xp->evaluate('string(//Invoice[Line/Description="Repasse de despesas"]/DocumentTotals/TaxPayable)'));

        $this->assertSame('', $xp->evaluate('string(//Invoice[Line/Description="Serviço"]/Line/TaxExemptionCode)'));
    }

    public function test_o_ficheiro_leva_a_marca_de_estrutura_provisoria(): void
    {
        $this->assertStringContainsString('ESTRUTURA PROVISÓRIA', $this->xml());
    }
}
