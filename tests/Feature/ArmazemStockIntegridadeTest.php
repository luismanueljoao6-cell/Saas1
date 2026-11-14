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
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Tests\TestCase;

class ArmazemStockIntegridadeTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(string $nif): array
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nif' => $nif,
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);

        $produto = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto de integridade',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $armazem = app(ArmazemService::class)->obterPrincipal($empresa);

        return [$empresa, $armazem, $produto];
    }

    public function test_idempotencia_ajustes_inventario_aberto_e_produto_inativo(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630100111');
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produto, '6.000', 'Entrada inicial');

        $primeiro = $stock->ajustar(
            $empresa, $armazem, $produto, '4.500', 'Contagem física', null, 'ajuste-001'
        );
        $repetido = $stock->ajustar(
            $empresa, $armazem, $produto, '4.500', 'Contagem física', null, 'ajuste-001'
        );

        $this->assertSame($primeiro->id, $repetido->id);
        $this->assertSame(2, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());
        $this->assertSame('4.500', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->value('quantidade'));

        try {
            $stock->ajustar(
                $empresa, $armazem, $produto, '4.000', 'Contagem física', null, 'ajuste-001'
            );
            $this->fail('A chave idempotente não deve ser reutilizada com outra contagem.');
        } catch (DomainException $exception) {
            $this->assertSame('A chave de idempotência já foi utilizada noutro movimento.', $exception->getMessage());
        }

        app(ArmazemInventarioService::class)->abrir($empresa, $armazem, [$produto->id]);

        try {
            $stock->entrada($empresa, $armazem, $produto, '1.000', 'Entrada durante contagem');
            $this->fail('Não deve permitir movimentos durante inventário aberto.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('inventário físico', $exception->getMessage());
        }

        [$empresaInativa, $armazemInativo, $produtoInativo] = $this->preparar('630100112');
        $produtoInativo->forceFill(['ativo' => false])->save();

        try {
            $stock->entrada($empresaInativa, $armazemInativo, $produtoInativo, '1.000', 'Produto inativo');
            $this->fail('Não deve permitir movimentar um produto inativo.');
        } catch (DomainException $exception) {
            $this->assertSame('Não é possível movimentar stock de um produto inativo.', $exception->getMessage());
        }
    }
}
