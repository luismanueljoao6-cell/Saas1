<?php

namespace Tests\Feature;

use DomainException;
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

class ArmazemInventarioServiceTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(string $nif = '630100201'): array
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nif' => $nif,
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);
        app(TenantManager::class)->set((int) $empresa->id);

        $produtoA = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto A',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);
        $produtoB = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto B',
            'unidade' => 'un',
            'preco_unitario' => '200.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $armazem = app(ArmazemService::class)->obterPrincipal($empresa);

        return [$empresa, $armazem, $produtoA, $produtoB];
    }

    public function test_abertura_cria_snapshot_e_registo_de_contagem(): void
    {
        [$empresa, $armazem, $produtoA, $produtoB] = $this->preparar();
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '10.000', 'Entrada inicial');

        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id, $produtoB->id]);
        $linhas = $inventario->linhas->keyBy('produto_id');

        $this->assertSame('10.000', (string) $linhas->get($produtoA->id)->quantidade_sistema);
        $this->assertSame('0.000', (string) $linhas->get($produtoB->id)->quantidade_sistema);

        $linha = $service->registarContagem($empresa, $inventario, $linhas->get($produtoA->id)->id, '8.500');
        $this->assertSame('-1.500', (string) $linha->diferenca);
    }

    public function test_bloqueia_movimentos_dos_produtos_durante_a_contagem(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100202');
        app(ArmazemStockService::class)->entrada($empresa, $armazem, $produtoA, '5.000', 'Entrada');
        app(ArmazemInventarioService::class)->abrir($empresa, $armazem, [$produtoA->id]);

        $this->expectException(DomainException::class);
        app(ArmazemStockService::class)->saida($empresa, $armazem, $produtoA, '1.000', 'Saída durante inventário');
    }

    public function test_conclusao_aplica_diferenca_com_movimento_de_ajuste(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100203');
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '10.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id]);
        $linha = $inventario->linhas->first();
        $service->registarContagem($empresa, $inventario, $linha->id, '7.250');

        $concluido = $service->concluir($empresa, $inventario);
        $saldo = ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('armazem_id', $armazem->id)
            ->where('produto_id', $produtoA->id)
            ->value('quantidade');

        $this->assertSame('concluido', $concluido->estado);
        $this->assertSame('7.250', (string) $saldo);
        $this->assertSame(2, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());
        $ajuste = ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('tipo', 'ajuste')->firstOrFail();
        $this->assertSame('-2.750', (string) $ajuste->quantidade);
    }

    public function test_nao_conclui_se_existir_produto_sem_contagem(): void
    {
        [$empresa, $armazem, $produtoA, $produtoB] = $this->preparar('630100204');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id, $produtoB->id]);
        $service->registarContagem($empresa, $inventario, $inventario->linhas->first()->id, '0.000');

        $this->expectException(DomainException::class);
        $service->concluir($empresa, $inventario);
    }

    public function test_cancelamento_liberta_movimentos_sem_aplicar_ajustes(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100205');
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '4.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id]);
        $cancelado = $service->cancelar($empresa, $inventario);

        $this->assertSame('cancelado', $cancelado->estado);
        $stock->saida($empresa, $armazem, $produtoA, '1.000', 'Saída após cancelamento');
        $this->assertSame('3.000', (string) ArmazemStockSaldo::withoutGlobalScopes()->value('quantidade'));
        $this->assertSame(2, ArmazemStockMovimento::withoutGlobalScopes()->count());
    }

    public function test_impede_duas_contagens_abertas_no_mesmo_armazem(): void
    {
        [$empresa, $armazem, $produtoA, $produtoB] = $this->preparar('630100206');
        $service = app(ArmazemInventarioService::class);
        $service->abrir($empresa, $armazem, [$produtoA->id]);

        $this->expectException(DomainException::class);
        $service->abrir($empresa, $armazem, [$produtoB->id]);
    }

    public function test_inventario_de_outra_empresa_nao_pode_ser_alterado(): void
    {
        [$empresaA, $armazemA, $produtoA] = $this->preparar('630100207');
        [$empresaB, $armazemB, $produtoB] = $this->preparar('630100208');

        // O inventário pertence à empresa A: criar o registo sob o contexto A.
        app(TenantManager::class)->set((int) $empresaA->id);
        $inventario = app(ArmazemInventarioService::class)->abrir($empresaA, $armazemA, [$produtoA->id]);

        // Simular o pedido autenticado de B antes de tentar alterar o inventário de A.
        app(TenantManager::class)->set((int) $empresaB->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('O contexto atual não corresponde à empresa do inventário.');
        app(ArmazemInventarioService::class)->registarContagem($empresaB, $inventario, $inventario->linhas->first()->id, '1.000');
    }
}
