<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Traits\BelongsToTenant;
use Tests\TestCase;

/**
 * Este teste assume o Tests\TestCase padrão da raiz de uma instalação
 * Laravel (existe em qualquer projeto novo) e uma ligação de base de dados
 * de teste configurada (ex.: sqlite em memória no phpunit.xml).
 *
 * Prova o comportamento mais crítico do módulo: um utilizador de uma
 * empresa NUNCA consegue ver dados de outra, mesmo por acidente — é este o
 * risco que o próprio pedido do sistema identifica como a maior
 * vulnerabilidade de uma aplicação SaaS multi-tenant.
 */
class IsolamentoTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('registos_teste_tenant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nome');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('registos_teste_tenant');

        parent::tearDown();
    }

    public function test_uma_empresa_nunca_ve_registos_de_outra_empresa(): void
    {
        $empresaA = Empresa::create(['nome_comercial' => 'Empresa A', 'nif' => '100000001']);
        $empresaB = Empresa::create(['nome_comercial' => 'Empresa B', 'nif' => '100000002']);

        $tenantManager = app(TenantManager::class);

        $tenantManager->set($empresaA->id);
        RegistoTesteTenant::create(['nome' => 'Registo da empresa A']);

        $tenantManager->set($empresaB->id);
        RegistoTesteTenant::create(['nome' => 'Registo da empresa B']);

        $tenantManager->set($empresaA->id);
        $this->assertCount(1, RegistoTesteTenant::all());
        $this->assertSame('Registo da empresa A', RegistoTesteTenant::first()->nome);

        $tenantManager->set($empresaB->id);
        $this->assertCount(1, RegistoTesteTenant::all());
        $this->assertSame('Registo da empresa B', RegistoTesteTenant::first()->nome);
    }

    public function test_sem_tenant_definido_a_query_fica_vazia_por_seguranca(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa C', 'nif' => '100000003']);

        app(TenantManager::class)->set($empresa->id);
        RegistoTesteTenant::create(['nome' => 'Registo da empresa C']);

        app(TenantManager::class)->clear();

        $this->assertCount(0, RegistoTesteTenant::all());
    }
}

/**
 * Model de apoio só para este teste, a demonstrar como qualquer model de
 * negócio de qualquer módulo deve usar a trait BelongsToTenant.
 */
class RegistoTesteTenant extends Model
{
    use BelongsToTenant;

    protected $table = 'registos_teste_tenant';

    protected $fillable = ['nome', 'empresa_id'];
}
