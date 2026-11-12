<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\CategoriaProduto;
use Modules\Faturacao\Models\Produto;
use Tests\TestCase;

class ProdutoCatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected User $utilizador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->empresa = Empresa::create([
            'nome_comercial' => 'Empresa Catálogo',
            'nif' => '630000301',
        ]);
        $this->empresa->aderirServicos([Servico::Faturacao]);

        $this->utilizador = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Operador Catálogo',
            'email' => 'catalogo@empresa.ao',
            'password' => 'Senha-Forte-123!',
        ]);
    }

    public function test_cria_categoria_e_produto_com_codigo_de_barras(): void
    {
        $this->actingAs($this->utilizador)
            ->post(route('faturacao.produtos.categorias.store'), [
                'nome' => 'Bebidas',
                'descricao' => 'Bebidas e águas',
            ])
            ->assertSessionHasNoErrors();

        $categoria = CategoriaProduto::query()->where('nome', 'Bebidas')->firstOrFail();

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.produtos.store'), [
                'nome' => 'Água 1,5 L',
                'codigo' => 'AGUA-15',
                'codigo_barras' => '5601234567890',
                'categoria_id' => $categoria->id,
                'unidade' => 'un',
                'preco_unitario' => '250.00',
                'taxa_iva' => '14',
            ])
            ->assertSessionHasNoErrors();

        $produto = Produto::query()->where('codigo_barras', '5601234567890')->firstOrFail();

        $this->assertSame($categoria->id, $produto->categoria_id);
        $this->assertSame('250.00', $produto->preco_unitario);
    }

    public function test_nao_aceita_codigo_de_barras_duplicado_na_mesma_empresa(): void
    {
        $this->actingAs($this->utilizador)
            ->post(route('faturacao.produtos.store'), [
                'nome' => 'Produto A',
                'codigo_barras' => '5601234567890',
                'preco_unitario' => '100.00',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.produtos.store'), [
                'nome' => 'Produto B',
                'codigo_barras' => '5601234567890',
                'preco_unitario' => '200.00',
            ])
            ->assertSessionHasErrors(['codigo_barras']);

        $this->assertSame(1, Produto::query()->count());
    }

    public function test_nao_aceita_categoria_de_outra_empresa(): void
    {
        $outraEmpresa = Empresa::create([
            'nome_comercial' => 'Outra Empresa',
            'nif' => '630000302',
        ]);
        $categoriaAlheia = CategoriaProduto::create([
            'empresa_id' => $outraEmpresa->id,
            'nome' => 'Categoria privada',
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.produtos.store'), [
                'nome' => 'Produto inválido',
                'categoria_id' => $categoriaAlheia->id,
                'preco_unitario' => '100.00',
            ])
            ->assertSessionHasErrors(['categoria_id']);

        $this->assertSame(0, Produto::query()->count());
    }
}
