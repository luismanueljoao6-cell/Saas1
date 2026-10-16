<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Jws;
use Modules\Faturacao\Support\QrCodeAgt;
use Tests\TestCase;

class ClienteCongeladoTest extends TestCase
{
    use RefreshDatabase;

    protected string $publica;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/congelado-priv-'.uniqid().'.pem';
        $this->publica = sys_get_temp_dir().'/congelado-pub-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);
        file_put_contents($this->publica, openssl_pkey_get_details($recurso)['key']);

        config([
            'faturacao.chave_privada_path' => $privada,
            'faturacao.chaves_dir' => sys_get_temp_dir().'/congelado-sem-chaves-'.uniqid(),
        ]);

        $this->beforeApplicationDestroyed(function () use ($privada) {
            @unlink($privada);
            @unlink($this->publica);
        });
    }

    protected function emitir(string $nif, ?string $nifCliente = null): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente Original', 'nif' => $nifCliente]);

        $servico = app(FaturaService::class);
        $linhas = [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]];

        return [$empresa, $cliente, $servico->emitir($servico->criarRascunho($empresa, $cliente, $linhas))];
    }

    public function test_emissao_congela_nif_nome_e_pais_do_cliente(): void
    {
        [, , $fatura] = $this->emitir('330000001', '987654321');

        $this->assertSame('Cliente Original', $fatura->cliente_nome);
        $this->assertSame('987654321', $fatura->cliente_nif);
        $this->assertSame('AO', $fatura->cliente_pais);
    }

    public function test_editar_o_cliente_depois_nao_altera_o_que_foi_assinado(): void
    {
        [, $cliente, $fatura] = $this->emitir('330000002', '987654321');
        $original = Jws::payload($fatura->hash);

        Cliente::withoutGlobalScopes()->whereKey($cliente->id)->update(['nome' => 'Nome Alterado Lda', 'nif' => '111111111']);
        $fatura = Fatura::withoutGlobalScopes()->findOrFail($fatura->id);

        $this->assertSame($original, $fatura->construirStringParaAssinar());
        $this->assertTrue(app(AssinaturaFiscalService::class)->verificar($fatura->construirStringParaAssinar(), $fatura->hash, $this->publica));

        $assinado = json_decode((string) $original, true);
        $this->assertSame('Cliente Original', $assinado['companyName']);
        $this->assertSame('987654321', $assinado['customerTaxID']);
    }

    public function test_recibo_tambem_congela_o_cliente(): void
    {
        [$empresa, $cliente, $fatura] = $this->emitir('330000003');

        $recibo = app(ReciboService::class)->emitir($empresa->id, $cliente->id, '500.00', 'dinheiro', $fatura->id);

        $this->assertSame('Cliente Original', $recibo->cliente_nome);
        $this->assertSame('AO', $recibo->cliente_pais);
    }

    public function test_url_do_qr_vem_do_documento_assinado(): void
    {
        [, , $fatura] = $this->emitir('330000004');

        $this->assertSame(QrCodeAgt::url('330000004', $fatura->numero_documento), $fatura->urlQrAgt());
    }

    public function test_rascunho_nao_tem_url_de_qr(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja', 'nif' => '330000005']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente Original']);
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($empresa, $cliente, [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]]);

        $this->assertNull($rascunho->urlQrAgt());
    }
}
