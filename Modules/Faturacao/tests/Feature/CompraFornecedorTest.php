<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Compra;
use Modules\Faturacao\Models\Fornecedor;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Models\StockSaldo;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CompraFornecedorTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected User $utilizador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->empresa = Empresa::create([
            'nome_comercial' => 'Empresa Compras',
            'nif' => '630000319',
        ]);
        $this->empresa->aderirServicos([Servico::Faturacao]);

        $this->utilizador = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Administrador Compras',
            'email' => 'compras@empresa.ao',
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

    public function test_regista_compra_e_atualiza_stock_uma_unica_vez_ao_receber(): void
    {
        $fornecedor = Fornecedor::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Distribuidora Angola',
            'pais' => 'AO',
            'ativo' => true,
        ]);
        $produto = Produto::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Papel A4',
            'unidade' => 'resma',
            'preco_unitario' => '2500.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
            'stock_minimo' => '2.000',
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.guardar'), [
                'fornecedor_id' => $fornecedor->id,
                'data_documento' => today()->toDateString(),
                'linhas' => [
                    0 => [
                        'selecionado' => '1',
                        'produto_id' => $produto->id,
                        'quantidade' => '4.000',
                        'preco_unitario' => '2300.00',
                        'taxa_iva' => '14',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $compra = Compra::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->firstOrFail();

        $this->assertSame('rascunho', $compra->estado);
        $this->assertSame('9200.00', (string) $compra->subtotal);
        $this->assertSame('1288.00', (string) $compra->total_iva);
        $this->assertSame('10488.00', (string) $compra->total);
        $this->assertSame('0.000', (string) ($produto->stockSaldo()->value('quantidade') ?? '0.000'));

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.receber', $compra))
            ->assertSessionHasNoErrors();

        app(TenantManager::class)->set((int) $this->empresa->id);
        $this->assertSame('4.000', (string) StockSaldo::query()->where('produto_id', $produto->id)->value('quantidade'));

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.receber', $compra))
            ->assertSessionHasErrors(['compra']);

        app(TenantManager::class)->set((int) $this->empresa->id);
        $this->assertSame('4.000', (string) StockSaldo::query()->where('produto_id', $produto->id)->value('quantidade'));
    }

    public function test_nao_permite_compra_com_fornecedor_de_outra_empresa(): void
    {
        $outraEmpresa = Empresa::create([
            'nome_comercial' => 'Outra Empresa Fornecedora',
            'nif' => '630000320',
        ]);
        $fornecedorAlheio = Fornecedor::create([
            'empresa_id' => $outraEmpresa->id,
            'nome' => 'Fornecedor Externo',
            'pais' => 'AO',
            'ativo' => true,
        ]);

        $produto = Produto::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Produto válido',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.guardar'), [
                'fornecedor_id' => $fornecedorAlheio->id,
                'data_documento' => today()->toDateString(),
                'linhas' => [
                    0 => [
                        'selecionado' => '1',
                        'produto_id' => $produto->id,
                        'quantidade' => '1.000',
                        'preco_unitario' => '100.00',
                        'taxa_iva' => '0',
                    ],
                ],
            ])
            ->assertNotFound();

        $this->assertSame(0, Compra::withoutGlobalScopes()->count());
    }
}
