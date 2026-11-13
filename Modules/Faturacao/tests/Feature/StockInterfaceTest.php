<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Models\StockMovimento;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected User $utilizador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->empresa = Empresa::create(['nome_comercial' => 'Empresa Stock UI', 'nif' => '630000307']);
        $this->empresa->aderirServicos([Servico::Faturacao]);
        $this->utilizador = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Administrador Stock',
            'email' => 'stock-ui@empresa.ao',
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

    public function test_administrador_pode_registar_entrada_e_ver_historico(): void
    {
        $produto = Produto::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Produto com stock',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
            'stock_minimo' => '2.000',
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.stock.store'), [
                'produto_id' => $produto->id,
                'tipo' => 'entrada',
                'quantidade' => '5.000',
                'motivo' => 'Receção inicial',
                'chave_idempotencia' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('faturacao.stock.index'))
            ->assertSessionHasNoErrors();

        // O middleware limpa o tenant no fim do pedido; reativamo-lo para
        // validar a relação protegida pelo TenantScope neste teste.
        app(TenantManager::class)->set((int) $this->empresa->id);

        $this->assertSame('5.000', $produto->stockSaldo()->value('quantidade'));
        $this->assertSame(1, StockMovimento::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());

        $this->actingAs($this->utilizador)
            ->get(route('faturacao.stock.index'))
            ->assertOk()
            ->assertSee('Gestão de stock')
            ->assertSee('Produto com stock')
            ->assertSee('Receção inicial');
    }

    public function test_nao_permite_registar_movimento_para_produto_de_outra_empresa(): void
    {
        $outraEmpresa = Empresa::create(['nome_comercial' => 'Outra empresa stock', 'nif' => '630000308']);
        $produtoAlheio = Produto::create([
            'empresa_id' => $outraEmpresa->id,
            'nome' => 'Produto alheio',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.stock.store'), [
                'produto_id' => $produtoAlheio->id,
                'tipo' => 'entrada',
                'quantidade' => '1.000',
                'motivo' => 'Tentativa cruzada',
                'chave_idempotencia' => (string) Str::uuid(),
            ])
            ->assertNotFound();

        $this->assertSame(0, StockMovimento::withoutGlobalScopes()->count());
    }
}
