<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Jws;
use Tests\TestCase;

class AssinaturaJwsTest extends TestCase
{
    use RefreshDatabase;

    protected string $privada;

    protected string $publica;

    protected string $dirChaves;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->privada, $this->publica] = $this->gerarPar(2048);
        $this->dirChaves = sys_get_temp_dir().'/faturacao-chaves-'.uniqid();

        config([
            'faturacao.chave_privada_path' => $this->privada,
            'faturacao.chaves_dir' => $this->dirChaves,
        ]);

        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->dirChaves.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dirChaves);
        });
    }

    /** @return array{0: string, 1: string} caminhos [privada, publica] */
    protected function gerarPar(int $bits): array
    {
        $recurso = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/faturacao-jws-'.uniqid().'.pem';
        $publica = sys_get_temp_dir().'/faturacao-jws-pub-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);
        file_put_contents($publica, openssl_pkey_get_details($recurso)['key']);

        $this->beforeApplicationDestroyed(function () use ($privada, $publica) {
            @unlink($privada);
            @unlink($publica);
        });

        return [$privada, $publica];
    }

    protected function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja JWS '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        return [$empresa, $cliente];
    }

    protected function emitir(Empresa $empresa, Cliente $cliente): Fatura
    {
        $servico = app(FaturaService::class);
        $linhas = [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]];

        return $servico->emitir($servico->criarRascunho($empresa, $cliente, $linhas));
    }

    public function test_fatura_emitida_guarda_jws_rs256_com_o_objecto_da_agt(): void
    {
        [$empresa, $cliente] = $this->cenario('320000001');
        $fatura = $this->emitir($empresa, $cliente);

        $partes = explode('.', $fatura->hash);
        $this->assertCount(3, $partes);
        $this->assertSame('{"alg":"RS256","typ":"JWT"}', Jws::deBase64url($partes[0]));

        $payload = json_decode(Jws::payload($fatura->hash), true);
        $this->assertSame($fatura->numero_documento, $payload['documentNo']);
        $this->assertSame('320000001', $payload['taxRegistrationNumber']);
        $this->assertSame('FT', $payload['documentType']);
        $this->assertSame('999999999', $payload['customerTaxID']);
        $this->assertSame('AO', $payload['customerCountry']);
        $this->assertSame('Cliente Teste', $payload['companyName']);
        $this->assertSame(['taxPayable' => 140, 'netTotal' => 1000, 'grossTotal' => 1140], $payload['documentTotals']);
    }

    public function test_assinatura_verifica_com_a_chave_publica_e_falha_se_adulterada(): void
    {
        [$empresa, $cliente] = $this->cenario('320000002');
        $fatura = $this->emitir($empresa, $cliente);
        $servico = app(AssinaturaFiscalService::class);

        $this->assertTrue($servico->verificar($fatura->construirStringParaAssinar(), $fatura->hash, $this->publica));

        $adulterado = str_replace('1140', '1141', Jws::payload($fatura->hash));
        $this->assertFalse($servico->verificar($adulterado, $fatura->hash, $this->publica));
    }

    public function test_chave_com_menos_de_2048_bits_e_recusada(): void
    {
        [$empresa, $cliente] = $this->cenario('320000003');
        [$fraca] = $this->gerarPar(1024);
        config(['faturacao.chave_privada_path' => $fraca]);

        $this->expectException(AssinaturaFiscalException::class);
        $this->expectExceptionMessage('2048');

        $this->emitir($empresa, $cliente);
    }

    public function test_cada_empresa_assina_com_a_sua_propria_chave_quando_existe(): void
    {
        [$empresa, $cliente] = $this->cenario('320000004');
        [$privadaEmpresa, $publicaEmpresa] = $this->gerarPar(2048);

        mkdir($this->dirChaves, 0777, true);
        copy($privadaEmpresa, $this->dirChaves.'/320000004.pem');

        $fatura = $this->emitir($empresa, $cliente);
        $servico = app(AssinaturaFiscalService::class);

        $this->assertTrue($servico->verificar($fatura->construirStringParaAssinar(), $fatura->hash, $publicaEmpresa));
        $this->assertFalse($servico->verificar($fatura->construirStringParaAssinar(), $fatura->hash, $this->publica));
    }

    public function test_recibo_assina_imposto_proporcional_da_fatura_por_excesso(): void
    {
        [$empresa, $cliente] = $this->cenario('320000005');
        $fatura = $this->emitir($empresa, $cliente); // 1000 + 140 = 1140

        $recibo = app(ReciboService::class)->emitir($empresa->id, $cliente->id, '500.00', 'dinheiro', $fatura->id);
        $payload = json_decode(Jws::payload($recibo->hash), true);

        $this->assertSame('RC', $payload['documentType']);
        $this->assertEqualsWithDelta(61.41, $payload['documentTotals']['taxPayable'], 0.0001);
        $this->assertEqualsWithDelta(438.59, $payload['documentTotals']['netTotal'], 0.0001);
        $this->assertSame(500, $payload['documentTotals']['grossTotal']);
    }
}
