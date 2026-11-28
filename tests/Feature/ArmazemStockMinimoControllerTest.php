<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Armazem\Services\ArmazemService;
use Modules\Armazem\Support\PapeisArmazem;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ArmazemStockMinimoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_configuracao_de_stock_minimo_cria_saldo_zero_e_atualiza_sem_duplicar(): void
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa Stock Mínimo',
            'nif' => '630200501',
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);
        app(TenantManager::class)->set((int) $empresa->id);

        $administrador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Administrador Stock',
            'email' => 'admin-stock-minimo@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        PapeisArmazem::provisionar((int) $empresa->id);

        $registrar = app(PermissionRegistrar::class);
        $equipaAnterior = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId((int) $empresa->id);
        $papel = Role::query()
            ->where('empresa_id', $empresa->id)
            ->where('name', 'Administrador')
            ->firstOrFail();
        $administrador->assignRole($papel);
        $registrar->setPermissionsTeamId($equipaAnterior);
        $registrar->forgetCachedPermissions();

        $armazem = app(ArmazemService::class)->obterPrincipal($empresa);
        $produto = Produto::create([
            'empresa_id' => $empresa->id,
            'codigo' => 'MIN-001',
            'nome' => 'Produto com stock mínimo',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $urlRelatorio = route('armazem.relatorios.stock');
        $urlGuardar = route('armazem.stock.minimos.guardar');

        $this->actingAs($administrador)
            ->from($urlRelatorio)
            ->post($urlGuardar, [
                'armazem_id' => $armazem->id,
                'produto_id' => $produto->id,
                'quantidade_minima' => '5.000',
            ])
            ->assertRedirect($urlRelatorio)
            ->assertSessionHas('sucesso');

        $this->assertDatabaseHas('armazem_stock_minimos', [
            'empresa_id' => $empresa->id,
            'armazem_id' => $armazem->id,
            'produto_id' => $produto->id,
            'quantidade_minima' => '5.000',
        ]);
        $this->assertDatabaseHas('armazem_stock_saldos', [
            'empresa_id' => $empresa->id,
            'armazem_id' => $armazem->id,
            'produto_id' => $produto->id,
            'quantidade' => '0.000',
        ]);

        $this->actingAs($administrador)
            ->from($urlRelatorio)
            ->post($urlGuardar, [
                'armazem_id' => $armazem->id,
                'produto_id' => $produto->id,
                'quantidade_minima' => '8.500',
            ])
            ->assertRedirect($urlRelatorio)
            ->assertSessionHas('sucesso');

        $this->assertDatabaseCount('armazem_stock_minimos', 1);
        $this->assertDatabaseCount('armazem_stock_saldos', 1);
        $this->assertDatabaseHas('armazem_stock_minimos', [
            'empresa_id' => $empresa->id,
            'armazem_id' => $armazem->id,
            'produto_id' => $produto->id,
            'quantidade_minima' => '8.500',
        ]);
    }
}
