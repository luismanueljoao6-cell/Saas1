<?php

namespace Tests\Feature;

use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Armazem\Models\ArmazemStockMovimento;
use Modules\Armazem\Models\ArmazemStockSaldo;
use Modules\Armazem\Services\ArmazemInventarioService;
use Modules\Armazem\Services\ArmazemService;
use Modules\Armazem\Services\ArmazemStockService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Tests\TestCase;

class ArmazemStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(string $nif = '630100101'): array
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nif' => $nif,
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);

        $produto = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto multi-armazém',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $armazem = app(ArmazemService::class)->obterPrincipal($empresa);

        return [$empresa, $armazem, $produto];
    }

    public function test_entrada_e_saida_atualizam_saldo_e_historico_do_armazem(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar();

        $service = app(ArmazemStockService::class);
        $entrada = $service->entrada($empresa, $armazem, $produto, '8.500', 'Receção inicial');
        $saida = $service->saida($empresa, $armazem, $produto, '2.250', 'Consumo interno');

        $this->assertSame('entrada', $entrada->tipo);
        $this->assertSame('8.500', $entrada->saldo_posterior);
        $this->assertSame('-2.250', $saida->quantidade);
        $this->assertSame('6.250', $saida->saldo_posterior);
        $this->assertSame('6.250', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('armazem_id', $armazem->id)
            ->where('produto_id', $produto->id)
            ->value('quantidade'));
        $this->assertSame(2, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('produto_id', $produto->id)
            ->count());
    }

    public function test_stock_e_independente_entre_dois_armazens_da_mesma_empresa(): void
    {
        [$empresa, $principal, $produto] = $this->preparar('630100102');
        $secundario = app(ArmazemService::class)->criar($empresa, 'SEC-01', 'Armazém secundário');
        $service = app(ArmazemStockService::class);

        $service->entrada($empresa, $principal, $produto, '5.000', 'Stock principal');
        $service->entrada($empresa, $secundario, $produto, '2.000', 'Stock secundário');
        $service->saida($empresa, $secundario, $produto, '1.000', 'Saída secundária');

        $this->assertSame('5.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('armazem_id', $principal->id)->value('quantidade'));
        $this->assertSame('1.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('armazem_id', $secundario->id)->value('quantidade'));
    }

    public function test_nao_permite_stock_negativo_no_armazem(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100103');

        $this->expectException(DomainException::class);
        app(ArmazemStockService::class)->saida($empresa, $armazem, $produto, '0.001', 'Saída sem saldo');
    }

    public function test_chave_de_idempotencia_nao_duplica_entrada(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100104');
        $service = app(ArmazemStockService::class);

        $primeiro = $service->entrada($empresa, $armazem, $produto, '3.000', 'Receção', null, 'rececao-001');
        $repetido = $service->entrada($empresa, $armazem, $produto, '3.000', 'Receção', null, 'rececao-001');

        $this->assertSame($primeiro->id, $repetido->id);
        $this->assertSame(1, ArmazemStockMovimento::withoutGlobalScopes()->count());
        $this->assertSame('3.000', (string) ArmazemStockSaldo::withoutGlobalScopes()->value('quantidade'));
    }

    public function test_chave_de_idempotencia_nao_pode_ser_reutilizada_com_dados_diferentes(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100105');
        $service = app(ArmazemStockService::class);
        $service->entrada($empresa, $armazem, $produto, '3.000', 'Receção', null, 'rececao-002');

        $this->expectException(DomainException::class);
        $service->entrada($empresa, $armazem, $produto, '4.000', 'Receção', null, 'rececao-002');
    }

    public function test_ajuste_define_contagem_fisica_e_regista_a_diferenca(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100106');
        $service = app(ArmazemStockService::class);
        $service->entrada($empresa, $armazem, $produto, '5.000', 'Entrada');
        $ajuste = $service->ajustar($empresa, $armazem, $produto, '3.750', 'Inventário físico');

        $this->assertSame('ajuste', $ajuste->tipo);
        $this->assertSame('-1.250', $ajuste->quantidade);
        $this->assertSame('3.750', $ajuste->saldo_posterior);
    }

    public function test_recusa_armazem_inativo_e_produto_de_outra_empresa(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100107');
        $armazem->forceFill(['ativo' => false])->save();

        try {
            app(ArmazemStockService::class)->entrada($empresa, $armazem, $produto, '1.000', 'Teste');
            $this->fail('Era esperado rejeitar o armazém inativo.');
        } catch (DomainException $exception) {
            $this->assertSame('Não é possível movimentar stock num armazém inativo.', $exception->getMessage());
        }

        $armazemAtivo = app(ArmazemService::class)->criar($empresa, 'ATIVO-02', 'Armazém ativo');
        [, , $outroProduto] = $this->preparar('630100108');

        $this->expectException(ModelNotFoundException::class);
        app(ArmazemStockService::class)->entrada(
            $empresa, $armazemAtivo, $outroProduto, '1.000', 'Produto de outra empresa'
        );
    }

    public function test_recusa_operacao_quando_tenant_atual_nao_corresponde_a_empresa(): void
    {
        [$empresaA, $armazemA, $produtoA] = $this->preparar('630100109');
        [$empresaB] = $this->preparar('630100110');
        app(TenantManager::class)->set((int) $empresaA->id);

        $this->expectException(DomainException::class);
        app(ArmazemStockService::class)->entrada(
            $empresaB, $armazemA, $produtoA, '1.000', 'Tenant divergente'
        );
    }

    public function test_ajuste_com_a_mesma_chave_de_idempotencia_nao_duplica_movimento(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100111');
        $service = app(ArmazemStockService::class);
        $service->entrada($empresa, $armazem, $produto, '6.000', 'Entrada inicial');

        $primeiro = $service->ajustar(
            $empresa, $armazem, $produto, '4.500', 'Contagem física', null, 'ajuste-001'
        );
        $repetido = $service->ajustar(
            $empresa, $armazem, $produto, '4.500', 'Contagem física', null, 'ajuste-001'
        );

        $this->assertSame($primeiro->id, $repetido->id);
        $this->assertSame(2, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());
        $this->assertSame('4.500', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->value('quantidade'));
    }

    public function test_chave_de_ajuste_nao_pode_ser_reutilizada_com_outra_contagem(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100112');
        $service = app(ArmazemStockService::class);
        $service->entrada($empresa, $armazem, $produto, '6.000', 'Entrada inicial');
        $service->ajustar($empresa, $armazem, $produto, '4.500', 'Contagem física', null, 'ajuste-002');

        $this->expectException(DomainException::class);
        $service->ajustar($empresa, $armazem, $produto, '4.000', 'Contagem física', null, 'ajuste-002');
    }

    public function test_bloqueia_movimentos_de_stock_enquanto_produto_esta_em_inventario(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100113');
        app(ArmazemInventarioService::class)->abrir($empresa, $armazem, [$produto->id]);

        $this->expectException(DomainException::class);
        app(ArmazemStockService::class)->entrada($empresa, $armazem, $produto, '1.000', 'Entrada durante contagem');
    }

    public function test_nao_permite_movimentos_de_produto_inativo(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100114');
        $produto->forceFill(['ativo' => false])->save();

        $this->expectException(DomainException::class);
        app(ArmazemStockService::class)->entrada($empresa, $armazem, $produto, '1.000', 'Entrada produto inativo');
    }

}
