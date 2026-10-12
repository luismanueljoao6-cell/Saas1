<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Prova que o tenant é definido ANTES do route model binding implícito.
 * Se a ordem estiver errada, o Cliente (BelongsToTenant) é resolvido sem
 * tenant e o próprio cliente da empresa dá 404.
 */
class OrdemMiddlewareTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_contexto_tenant_e_limpo_depois_do_pedido(): void
    {
        Route::middleware(['web', 'auth', 'tenant'])->get('/_teste/tenant-context', function () {
            return (string) app(TenantManager::class)->id();
        });

        $empresa = Empresa::create(['nome_comercial' => 'Empresa Contexto', 'nif' => '420000001']);
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Contexto',
            'email' => 'contexto@example.test',
            'password' => Hash::make('Senha-segura-123'),
        ]);

        $this->actingAs($utilizador)
            ->get('/_teste/tenant-context')
            ->assertOk()
            ->assertSee((string) $empresa->id);

        $this->assertFalse(app(TenantManager::class)->has());
    }

    public function test_binding_implicito_de_model_com_tenant_funciona_e_isola_empresas(): void
    {
        Route::middleware(['web', 'auth', 'tenant'])
            ->get('/_teste/clientes/{cliente}', fn (Cliente $cliente) => $cliente->nome);

        $empresaA = Empresa::create(['nome_comercial' => 'Empresa A', 'nif' => '410000001']);
        $empresaB = Empresa::create(['nome_comercial' => 'Empresa B', 'nif' => '410000002']);

        $utilizadorA = User::create([
            'empresa_id' => $empresaA->id,
            'name' => 'Utilizador A',
            'email' => 'a@example.test',
            'password' => Hash::make('Senha-segura-123'),
        ]);

        $clienteA = Cliente::create(['empresa_id' => $empresaA->id, 'nome' => 'Cliente da A', 'nif' => null]);
        $clienteB = Cliente::create(['empresa_id' => $empresaB->id, 'nome' => 'Cliente da B', 'nif' => null]);

        $this->actingAs($utilizadorA)
            ->get('/_teste/clientes/'.$clienteA->id)
            ->assertOk()
            ->assertSee('Cliente da A');

        $this->actingAs($utilizadorA)
            ->get('/_teste/clientes/'.$clienteB->id)
            ->assertNotFound();
    }
}
