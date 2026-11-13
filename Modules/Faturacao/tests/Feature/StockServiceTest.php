<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Models\StockMovimento;
use Modules\Faturacao\Models\StockSaldo;
use Modules\Faturacao\Services\StockService;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function criarProduto(string $nif = '630000301'): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos([Servico::Faturacao]);
        $produto = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto de teste',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        return [$empresa, $produto];
    }

    public function test_entrada_saida_e_saldo_sao_registados_com_historico(): void
    {
        [$empresa, $produto] = $this->criarProduto();
        $service = app(StockService::class);

        $entrada = $service->entrada($empresa, $produto, '10.500', 'Compra inicial');
        $saida = $service->saida($empresa, $produto, '2.250', 'Venda manual');

        $this->assertSame('entrada', $entrada->tipo);
        $this->assertSame('10.500', $entrada->saldo_posterior);
        $this->assertSame('-2.250', $saida->quantidade);
        $this->assertSame('8.250', $saida->saldo_posterior);
        $this->assertSame('8.250', StockSaldo::withoutGlobalScopes()->where('produto_id', $produto->id)->value('quantidade'));
        $this->assertSame(2, StockMovimento::withoutGlobalScopes()->where('produto_id', $produto->id)->count());
    }

    public function test_nao_permite_stock_negativo(): void
    {
        [$empresa, $produto] = $this->criarProduto('630000302');

        $this->expectException(\DomainException::class);
        app(StockService::class)->saida($empresa, $produto, '1', 'Saída sem stock');
    }

    public function test_ajuste_define_contagem_fisica_e_regista_diferenca(): void
    {
        [$empresa, $produto] = $this->criarProduto('630000303');
        $service = app(StockService::class);
        $service->entrada($empresa, $produto, '5.000', 'Entrada');
        $ajuste = $service->ajustar($empresa, $produto, '3.750', 'Contagem física');

        $this->assertSame('ajuste', $ajuste->tipo);
        $this->assertSame('-1.250', $ajuste->quantidade);
        $this->assertSame('3.750', $ajuste->saldo_posterior);
    }

    public function test_chave_de_idempotencia_impede_duplicar_movimento(): void
    {
        [$empresa, $produto] = $this->criarProduto('630000304');
        $service = app(StockService::class);

        $primeiro = $service->entrada($empresa, $produto, '4.000', 'Receção', null, 'rececao-001');
        $segundo = $service->entrada($empresa, $produto, '4.000', 'Receção', null, 'rececao-001');

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame(1, StockMovimento::withoutGlobalScopes()->where('produto_id', $produto->id)->count());
        $this->assertSame('4.000', StockSaldo::withoutGlobalScopes()->where('produto_id', $produto->id)->value('quantidade'));
    }

    public function test_repetir_saida_com_a_mesma_chave_nao_recalcula_saldo_atual(): void
    {
        [$empresa, $produto] = $this->criarProduto('630000307');
        $service = app(StockService::class);
        $service->entrada($empresa, $produto, '5.000', 'Stock inicial');

        $primeiro = $service->saida($empresa, $produto, '4.000', 'Venda externa', null, 'saida-retry-001');
        $service->entrada($empresa, $produto, '4.000', 'Reposição posterior');
        $repetido = $service->saida($empresa, $produto, '4.000', 'Venda externa', null, 'saida-retry-001');

        $this->assertSame($primeiro->id, $repetido->id);
        $this->assertSame('5.000', StockSaldo::withoutGlobalScopes()->where('produto_id', $produto->id)->value('quantidade'));
        $this->assertSame(3, StockMovimento::withoutGlobalScopes()->where('produto_id', $produto->id)->count());
    }

    public function test_repetir_ajuste_com_a_mesma_chave_e_seguro_e_nao_duplica_movimento(): void
    {
        [$empresa, $produto] = $this->criarProduto('630000305');
        $service = app(StockService::class);
        $service->entrada($empresa, $produto, '5.000', 'Entrada');

        $primeiro = $service->ajustar($empresa, $produto, '3.500', 'Contagem física', null, 'ajuste-001');
        $segundo = $service->ajustar($empresa, $produto, '3.500', 'Contagem física', null, 'ajuste-001');

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame(2, StockMovimento::withoutGlobalScopes()->where('produto_id', $produto->id)->count());
        $this->assertSame('3.500', StockSaldo::withoutGlobalScopes()->where('produto_id', $produto->id)->value('quantidade'));
    }

    public function test_mesma_chave_nao_pode_ser_reutilizada_noutro_produto_da_empresa(): void
    {
        [$empresa, $produto] = $this->criarProduto('630000309');
        $outroProduto = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Segundo produto de teste',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);
        $service = app(StockService::class);
        $service->entrada($empresa, $produto, '2.000', 'Receção', null, 'chave-partilhada');

        $this->expectException(\\DomainException::class);
        $service->entrada($empresa, $outroProduto, '2.000', 'Receção', null, 'chave-partilhada');
    }

    public function test_reutilizar_chave_com_dados_diferentes_e_rejeitado(): void
    {
        [$empresa, $produto] = $this->criarProduto('630000306');
        $service = app(StockService::class);
        $service->entrada($empresa, $produto, '2.000', 'Receção', null, 'chave-unica');

        $this->expectException(\DomainException::class);
        $service->entrada($empresa, $produto, '3.000', 'Receção', null, 'chave-unica');
    }
}
