<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Tests\TestCase;

/**
 * O IVA de cada linha arredonda-se por excesso (AGT). Creditar uma linha em várias notas parciais
 * pode então somar 1 cêntimo a mais do que o IVA da linha original: isso é arredondamento, não
 * excesso de crédito, e não pode impedir a anulação legítima do valor todo.
 */
class CreditoParcialArredondamentoIvaTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-arred-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        config(['faturacao.chave_privada_path' => $caminho, 'faturacao.agt.ativo' => false]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Arredondamento', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    protected function fatura(string $quantidade, string $preco): Fatura
    {
        $servico = app(FaturaService::class);

        return $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Artigo', 'quantidade' => $quantidade, 'preco_unitario' => $preco, 'taxa_iva' => '14'],
        ]));
    }

    protected function credito(Fatura $fatura, string $quantidade, string $preco): NotaCreditoDebito
    {
        $notas = app(NotaCreditoDebitoService::class);

        return $notas->emitir($notas->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', 'Devolução', [
            ['descricao' => 'Artigo', 'quantidade' => $quantidade, 'preco_unitario' => $preco, 'taxa_iva' => '14'],
        ], $fatura->id));
    }

    public function test_creditar_a_mesma_linha_em_duas_notas_nao_e_barrado_por_um_centimo_de_arredondamento(): void
    {
        // 3 x 1,01 = 3,03 + IVA 0,43 (0,4242 por excesso) = 3,46
        $fatura = $this->fatura('3', '1.01');
        $this->assertSame('3.46', (string) $fatura->valor_total);

        // 1 x 1,01 => IVA 0,15; 2 x 1,01 => IVA 0,29: 1,16 + 2,31 = 3,47 (> 3,46 só por arredondamento).
        $primeira = $this->credito($fatura, '1', '1.01');
        $segunda = $this->credito($fatura, '2', '1.01');

        $this->assertSame('1.16', (string) $primeira->valor_total);
        $this->assertSame('2.31', (string) $segunda->valor_total);
        $this->assertSame('emitida', $segunda->estado);
    }

    public function test_o_valor_liquido_continua_limitado_ao_da_fatura(): void
    {
        $fatura = $this->fatura('3', '1.01');
        $this->credito($fatura, '1', '1.01');
        $this->credito($fatura, '2', '1.01');

        $this->expectException(\DomainException::class);

        // Já está tudo creditado: mais uma unidade excede o valor líquido faturado.
        $this->credito($fatura, '1', '1.01');
    }

    public function test_um_credito_claramente_acima_da_fatura_continua_barrado(): void
    {
        $fatura = $this->fatura('1', '100.00');

        $this->expectException(\DomainException::class);

        $this->credito($fatura, '1', '100.50');
    }
}
