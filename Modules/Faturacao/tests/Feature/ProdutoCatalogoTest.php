<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\CategoriaProduto;
use Modules\Faturacao\Models\Produto;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->empresa->id);
        $papel = Role::firstOrCreate([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'empresa_id' => $this->empresa->id,
        ]);
        $this->utilizador->assignRole($papel);
        $registrar->setPermissionsTeamId(null);
    }

    public function test_cria_categoria_e_produto_com_codigo_de_barras(): void
    {
        $this->actingAs($this->utilizador)
            ->post(route('faturacao.produtos.categorias.store'), [
                'nome' => 'Bebidas',
                'descricao' => 'Bebidas e águas',
            ])
            ->assertSessionHasNoErrors();

        $categoria = CategoriaProduto::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->where('nome', 'Bebidas')
            ->firstOrFail();

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

        $produto = Produto::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->where('codigo_barras', '5601234567890')
            ->firstOrFail();

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

        $this->assertSame(1, Produto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());
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

        $this->assertSame(0, Produto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());
    }

    public function test_filtros_do_catalogo_limitam_categoria_e_estado_de_stock(): void
    {
        $categoria = CategoriaProduto::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Acessórios',
        ]);

        $produto = Produto::create([
            'empresa_id' => $this->empresa->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Cabo USB',
            'unidade' => 'un',
            'preco_unitario' => '500.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
            'stock_minimo' => '3.000',
        ]);

        $this->actingAs($this->utilizador)
            ->get(route('faturacao.produtos.index', ['categoria_id' => $categoria->id, 'stock' => 'baixo']))
            ->assertOk()
            ->assertSee('Cabo USB');

        $this->actingAs($this->utilizador)
            ->get(route('faturacao.produtos.index', ['categoria_id' => $categoria->id, 'stock' => 'sem_stock']))
            ->assertOk()
            ->assertSee('Cabo USB');
    }

    public function test_filtro_de_categoria_nao_aceita_categoria_de_outra_empresa(): void
    {
        $outraEmpresa = Empresa::create([
            'nome_comercial' => 'Empresa externa',
            'nif' => '630000303',
        ]);
        $categoriaAlheia = CategoriaProduto::create([
            'empresa_id' => $outraEmpresa->id,
            'nome' => 'Privada',
        ]);

        $this->actingAs($this->utilizador)
            ->get(route('faturacao.produtos.index', ['categoria_id' => $categoriaAlheia->id]))
            ->assertSessionHasErrors(['categoria_id']);
    }
}
