<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AgtDocumentoBuilder;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Tests\TestCase;

class NotaDebitoSemIvaTest extends TestCase
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

    protected function linha(float $preco, float $taxa): array
    {
        return [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => $taxa]];
    }

    protected function faturaEmitida(Empresa $e, Cliente $c, float $preco = 1000): Fatura
    {
        $s = app(FaturaService::class);

        return $s->emitir($s->criarRascunho($e, $c, $this->linha($preco, 14)));
    }

    public function test_nota_de_debito_com_iva_e_rejeitada(): void
    {
        [$e, $c] = $this->cenario('610000001');

        $this->expectException(\DomainException::class);
        app(NotaCreditoDebitoService::class)->criarRascunho($e->id, $c->id, 'debito', 'Juros de mora', $this->linha(1000, 14));
    }

    public function test_nota_de_debito_sem_iva_e_emitida_sem_imposto(): void
    {
        [$e, $c] = $this->cenario('610000002');
        $servico = app(NotaCreditoDebitoService::class);

        $nota = $servico->emitir($servico->criarRascunho($e->id, $c->id, 'debito', 'Juros de mora', $this->linha(1000, 0)));

        $this->assertSame('emitida', $nota->estado);
        $this->assertSame('1000.00', $nota->valor_sem_iva);
        $this->assertSame('0.00', $nota->valor_iva);
        $this->assertSame('1000.00', $nota->valor_total);
    }

    public function test_pedido_agt_da_nota_de_debito_nao_tem_bloco_de_impostos(): void
    {
        [$e, $c] = $this->cenario('610000003');
        $servico = app(NotaCreditoDebitoService::class);
        $nota = $servico->emitir($servico->criarRascunho($e->id, $c->id, 'debito', 'Juros de mora', $this->linha(1000, 0)));

        $documento = app(AgtDocumentoBuilder::class)->construir($nota);

        $this->assertArrayNotHasKey('taxes', $documento['lines'][0]);
    }

    public function test_configuracao_pode_voltar_a_permitir_iva_na_nota_de_debito(): void
    {
        config(['faturacao.nota_debito_liquida_iva' => true]);
        [$e, $c] = $this->cenario('610000004');

        $nota = app(NotaCreditoDebitoService::class)->criarRascunho($e->id, $c->id, 'debito', 'Encargos', $this->linha(1000, 14));

        $this->assertSame('140.00', $nota->valor_iva);
    }

    public function test_nota_de_credito_nao_pode_creditar_a_uma_taxa_que_nao_foi_faturada(): void
    {
        [$e, $c] = $this->cenario('610000005');
        $fatura = $this->faturaEmitida($e, $c, 1000); // 1000 a 14% => total 1140
        $nc = app(NotaCreditoDebitoService::class);

        // 900 a 0% cabe no total bruto (1140), mas não existe valor faturado a 0%.
        // (Uma linha isenta tem de trazer o motivo de isenção: M10 é só um código válido da tabela.)
        $linhaIsenta = [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 900, 'taxa_iva' => 0, 'motivo_isencao' => 'M10']];
        $rascunho = $nc->criarRascunho($e->id, $c->id, 'credito', 'Taxa errada', $linhaIsenta, $fatura->id);

        $this->expectException(\DomainException::class);
        $nc->emitir($rascunho);
    }

    public function test_notas_de_credito_parciais_acumulam_ate_ao_limite_da_taxa(): void
    {
        [$e, $c] = $this->cenario('610000006');
        $fatura = $this->faturaEmitida($e, $c, 1000);
        $nc = app(NotaCreditoDebitoService::class);

        $primeira = $nc->emitir($nc->criarRascunho($e->id, $c->id, 'credito', 'Parcial 1', $this->linha(500, 14), $fatura->id));
        $segunda = $nc->emitir($nc->criarRascunho($e->id, $c->id, 'credito', 'Parcial 2', $this->linha(500, 14), $fatura->id));

        $this->assertSame('emitida', $primeira->estado);
        $this->assertSame('emitida', $segunda->estado);

        $terceira = $nc->criarRascunho($e->id, $c->id, 'credito', 'Excesso', $this->linha(1, 14), $fatura->id);

        $this->expectException(\DomainException::class);
        $nc->emitir($terceira);
    }
}
