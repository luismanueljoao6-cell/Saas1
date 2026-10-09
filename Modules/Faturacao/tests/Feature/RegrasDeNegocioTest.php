<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Support\Dinheiro;
use Tests\TestCase;

class RegrasDeNegocioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        config(['faturacao.chave_privada_path' => $caminho]);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));
    }

    protected function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);

        return [$empresa, $cliente];
    }

    protected function linha(float $preco = 1000): array
    {
        return [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => 14]];
    }

    protected function faturaEmitida(Empresa $e, Cliente $c, float $preco = 1000): Fatura
    {
        $s = app(FaturaService::class);

        return $s->emitir($s->criarRascunho($e, $c, $this->linha($preco)));
    }

    public function test_calculo_monetario_e_exato(): void
    {
        $this->assertSame(['sem_iva' => '0.30', 'iva' => '0.04', 'total' => '0.34'], Dinheiro::calcularLinha(3, '0.10', 14));
        $this->assertSame(['sem_iva' => '59.97', 'iva' => '8.40', 'total' => '68.37'], Dinheiro::calcularLinha(3, '19.99', 14));
    }

    public function test_emitir_duas_vezes_nao_consome_segundo_numero(): void
    {
        [$e, $c] = $this->cenario('600000001');
        $s = app(FaturaService::class);
        $f = $s->emitir($s->criarRascunho($e, $c, $this->linha()));
        $f2 = $s->emitir($f);

        $this->assertSame($f->numero_sequencial, $f2->numero_sequencial);
        $this->assertSame(1, (int) \DB::table('series')->where('empresa_id', $e->id)->where('tipo_documento', 'FT')->value('ultimo_numero'));
    }

    public function test_cliente_de_outra_empresa_e_rejeitado(): void
    {
        [$e1] = $this->cenario('600000002');
        [, $clienteDeOutra] = $this->cenario('600000003');

        $this->expectException(\DomainException::class);
        app(FaturaService::class)->criarRascunho($e1, $clienteDeOutra, $this->linha());
    }

    public function test_anulacao_total_so_pode_ser_criada_uma_vez(): void
    {
        [$e, $c] = $this->cenario('600000004');
        $fatura = $this->faturaEmitida($e, $c);
        $nc = app(NotaCreditoDebitoService::class);
        $nc->criarParaAnularFatura($fatura, 'Erro');

        $this->expectException(\DomainException::class);
        $nc->criarParaAnularFatura($fatura, 'Segunda tentativa');
    }

    public function test_nota_de_credito_nao_pode_exceder_o_valor_da_fatura(): void
    {
        [$e, $c] = $this->cenario('600000005');
        $fatura = $this->faturaEmitida($e, $c, 1000);
        $nc = app(NotaCreditoDebitoService::class);
        $rascunho = $nc->criarRascunho($e->id, $c->id, 'credito', 'Excesso', $this->linha(2000), $fatura->id);

        $this->expectException(\DomainException::class);
        $nc->emitir($rascunho);
    }

    public function test_tipo_de_nota_invalido_e_rejeitado(): void
    {
        [$e, $c] = $this->cenario('600000006');

        $this->expectException(\InvalidArgumentException::class);
        app(NotaCreditoDebitoService::class)->criarRascunho($e->id, $c->id, 'foo', 'x', $this->linha());
    }
}
