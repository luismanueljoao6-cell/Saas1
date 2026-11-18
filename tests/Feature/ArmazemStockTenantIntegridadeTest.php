<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Armazem\Services\ArmazemService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Tests\TestCase;

class ArmazemStockTenantIntegridadeTest extends TestCase
{
    use RefreshDatabase;

    private function prepararEmpresa(string $nif): array
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nif' => $nif,
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);
        app(TenantManager::class)->set((int) $empresa->id);

        $produto = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto '.$nif,
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
            'stock_minimo' => '0.000',
        ]);

        $armazem = app(ArmazemService::class)->obterPrincipal($empresa);

        return [$empresa, $produto, $armazem];
    }

    private function inserirInventario(int $empresaId, int $armazemId): int
    {
        return (int) DB::table('armazem_inventarios')->insertGetId([
            'empresa_id' => $empresaId,
            'armazem_id' => $armazemId,
            'estado' => 'em_contagem',
            'aberto_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function inserirLinha(int $empresaId, int $inventarioId, int $produtoId): int
    {
        return (int) DB::table('armazem_inventario_linhas')->insertGetId([
            'empresa_id' => $empresaId,
            'inventario_id' => $inventarioId,
            'produto_id' => $produtoId,
            'quantidade_sistema' => '1.000',
            'quantidade_contada' => '1.000',
            'diferenca' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertViolacao(callable $operacao): void
    {
        try {
            $operacao();
        } catch (QueryException) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('A base de dados devia impedir a associação entre empresas ou inventários diferentes.');
    }

    public function test_saldo_nao_pode_referenciar_armazem_de_outra_empresa(): void
    {
        [$empresaA, $produtoA] = $this->prepararEmpresa('630200001');
        [, , $armazemB] = $this->prepararEmpresa('630200002');

        $this->assertViolacao(fn () => DB::table('armazem_stock_saldos')->insert([
            'empresa_id' => $empresaA->id,
            'armazem_id' => $armazemB->id,
            'produto_id' => $produtoA->id,
            'quantidade' => '1.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_movimento_nao_pode_referenciar_produto_de_outra_empresa(): void
    {
        [$empresaA, , $armazemA] = $this->prepararEmpresa('630200003');
        [, $produtoB] = $this->prepararEmpresa('630200004');

        $this->assertViolacao(fn () => DB::table('armazem_stock_movimentos')->insert([
            'empresa_id' => $empresaA->id,
            'armazem_id' => $armazemA->id,
            'produto_id' => $produtoB->id,
            'tipo' => 'entrada',
            'quantidade' => '1.000',
            'saldo_anterior' => '0.000',
            'saldo_posterior' => '1.000',
            'motivo' => 'Teste de isolamento',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_stock_minimo_nao_pode_referenciar_produto_de_outra_empresa(): void
    {
        [$empresaA, , $armazemA] = $this->prepararEmpresa('630200005');
        [, $produtoB] = $this->prepararEmpresa('630200006');

        $this->assertViolacao(fn () => DB::table('armazem_stock_minimos')->insert([
            'empresa_id' => $empresaA->id,
            'armazem_id' => $armazemA->id,
            'produto_id' => $produtoB->id,
            'quantidade_minima' => '2.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_inventario_nao_pode_referenciar_armazem_de_outra_empresa(): void
    {
        [$empresaA] = $this->prepararEmpresa('630200007');
        [, , $armazemB] = $this->prepararEmpresa('630200008');

        $this->assertViolacao(fn () => $this->inserirInventario(
            (int) $empresaA->id,
            (int) $armazemB->id,
        ));
    }

    public function test_linha_de_inventario_nao_pode_referenciar_produto_de_outra_empresa(): void
    {
        [$empresaA, $produtoA, $armazemA] = $this->prepararEmpresa('630200009');
        [, $produtoB] = $this->prepararEmpresa('630200010');
        $inventarioA = $this->inserirInventario((int) $empresaA->id, (int) $armazemA->id);

        $this->assertViolacao(fn () => $this->inserirLinha(
            (int) $empresaA->id,
            $inventarioA,
            (int) $produtoB->id,
        ));
    }

    public function test_contagem_nao_pode_usar_linha_de_outro_inventario_da_mesma_empresa(): void
    {
        [$empresa, $produto, $armazem] = $this->prepararEmpresa('630200011');
        $inventarioA = $this->inserirInventario((int) $empresa->id, (int) $armazem->id);
        $inventarioB = $this->inserirInventario((int) $empresa->id, (int) $armazem->id);
        $linhaB = $this->inserirLinha((int) $empresa->id, $inventarioB, (int) $produto->id);

        $this->assertViolacao(fn () => DB::table('armazem_inventario_contagens')->insert([
            'empresa_id' => $empresa->id,
            'inventario_id' => $inventarioA,
            'linha_id' => $linhaB,
            'quantidade_sistema' => '1.000',
            'quantidade_contada' => '2.000',
            'diferenca' => '1.000',
            'registado_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
