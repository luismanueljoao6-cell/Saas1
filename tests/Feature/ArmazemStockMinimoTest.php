<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Armazem\Models\ArmazemStockMinimo;
use Modules\Armazem\Services\ArmazemService;
use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Tests\TestCase;

class ArmazemStockMinimoTest extends TestCase
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
            'nome' => 'Produto com limite',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $armazem = app(ArmazemService::class)->obterPrincipal($empresa);

        return [$empresa, $armazem, $produto];
    }

    public function test_guarda_limite_de_stock_por_empresa_armazem_e_produto(): void
    {
        [$empresaA, $armazemA, $produtoA] = $this->preparar('630200201');
        [$empresaB, $armazemB, $produtoB] = $this->preparar('630200202');

        $limiteA = ArmazemStockMinimo::create([
            'empresa_id' => $empresaA->id,
            'armazem_id' => $armazemA->id,
            'produto_id' => $produtoA->id,
            'quantidade_minima' => '5.000',
        ]);
        $limiteB = ArmazemStockMinimo::create([
            'empresa_id' => $empresaB->id,
            'armazem_id' => $armazemB->id,
            'produto_id' => $produtoB->id,
            'quantidade_minima' => '12.500',
        ]);

        $this->assertSame('5.000', $limiteA->quantidade_minima);
        $this->assertSame('12.500', $limiteB->quantidade_minima);
        $this->assertSame(2, ArmazemStockMinimo::withoutGlobalScopes()->count());
    }

    public function test_impede_limites_duplicados_para_o_mesmo_produto_no_mesmo_armazem(): void
    {
        [$empresa, $armazem, $produto] = $this->preparar('630200203');
        $dados = [
            'empresa_id' => $empresa->id,
            'armazem_id' => $armazem->id,
            'produto_id' => $produto->id,
            'quantidade_minima' => '5.000',
        ];

        ArmazemStockMinimo::create($dados);

        $this->expectException(QueryException::class);
        ArmazemStockMinimo::create([...$dados, 'quantidade_minima' => '8.000']);
    }
}
