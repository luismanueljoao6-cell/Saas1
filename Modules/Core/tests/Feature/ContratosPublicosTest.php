<?php

namespace Modules\Core\Tests\Feature;

use Modules\Core\Contracts\FeatureAccessInterface;
use Modules\Core\Contracts\NotificationDispatcherInterface;
use Modules\Core\Contracts\TenantContextInterface;
use Modules\Core\Contracts\TenantProvisionerInterface;
use Modules\Core\Exceptions\TenantNaoEncontradoException;
use Modules\Core\Notifications\NotificationDispatcher;
use Modules\Core\Services\FeatureAccess;
use Modules\Core\Services\TenantContext;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantProvisionerRegistry;
use Modules\Core\Support\Servico;
use Tests\TestCase;

class ContratosPublicosTest extends TestCase
{
    public function test_contratos_resolvem_para_as_implementacoes_do_core(): void
    {
        $this->assertInstanceOf(AuditLogger::class, $this->app->make(AuditLoggerInterface::class));\n        $this->assertInstanceOf(ModuleRegistry::class, $this->app->make(ModuleRegistryInterface::class));\n        $this->assertInstanceOf(Authorization::class, $this->app->make(AuthorizationInterface::class));\n        $this->assertInstanceOf(TenantContext::class, $this->app->make(TenantContextInterface::class));
        $this->assertInstanceOf(FeatureAccess::class, $this->app->make(FeatureAccessInterface::class));
        $this->assertInstanceOf(NotificationDispatcher::class, $this->app->make(NotificationDispatcherInterface::class));
        $this->assertSame(
            $this->app->make(TenantProvisionerRegistry::class),
            $this->app->make(TenantProvisionerInterface::class),
        );
    }

    public function test_catalogo_de_modulos_identifica_o_core_sem_expor_operacoes_de_escrita(): void
    {
        $catalogo = $this->app->make(ModuleRegistryInterface::class);
        $core = $catalogo->find('Core');

        $this->assertNotNull($core);
        $this->assertSame('Core', $core['name']);
        $this->assertTrue($core['enabled']);
        $this->assertContains('Core', array_column($catalogo->all(), 'name'));
        $this->assertFalse($catalogo->isEnabled('ModuloInexistente'));
    }

    public function test_contrato_de_autorizacao_delega_no_gate_do_laravel(): void
    {
        Gate::define('core.contrato.autorizado', fn () => true);
        Gate::define('core.contrato.negado', fn () => false);

        $autorizacao = $this->app->make(AuthorizationInterface::class);

        $this->assertTrue($autorizacao->allows('core.contrato.autorizado'));
        $this->assertFalse($autorizacao->allows('core.contrato.negado'));
        $this->assertTrue($autorizacao->authorize('core.contrato.autorizado')->allowed());
    }

    public function test_contexto_sem_empresa_falha_em_seguranca(): void
    {
        $contexto = $this->app->make(TenantContextInterface::class);

        $this->assertNull($contexto->id());
        $this->assertFalse($contexto->hasTenant());

        $this->expectException(TenantNaoEncontradoException::class);
        $contexto->requireTenant();
    }

    public function test_id_empresa_explicito_nao_permite_consulta_sem_tenant(): void
    {
        $repositorio = \\Mockery::mock(EmpresaRepositoryInterface::class);
        $repositorio->shouldNotReceive('encontrarPorId');
        $this->app->instance(EmpresaRepositoryInterface::class, $repositorio);

        $acesso = $this->app->make(FeatureAccessInterface::class);

        $this->assertFalse($acesso->servicoAtivo(Servico::Faturacao, 7));
    }

    public function test_contexto_acompanha_o_tenant_ativo_e_a_limpeza(): void
    {
        $contexto = $this->app->make(TenantContextInterface::class);
        $tenant = $this->app->make(TenantManager::class);

        $tenant->set(7);
        $this->assertTrue($contexto->hasTenant());
        $this->assertSame(7, $contexto->requireTenant());

        $tenant->clear();
        $this->assertFalse($contexto->hasTenant());
    }

    public function test_servico_nao_e_concedido_sem_empresa_nem_a_outra_empresa(): void
    {
        $acesso = $this->app->make(FeatureAccessInterface::class);

        $this->assertFalse($acesso->servicoAtivo(Servico::Faturacao));

        $this->app->make(TenantManager::class)->set(7);
        $this->assertFalse($acesso->servicoAtivo(Servico::Faturacao, 8));
    }
}
