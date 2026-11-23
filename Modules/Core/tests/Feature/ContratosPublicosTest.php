<?php

namespace Modules\Core\Tests\Feature;

use Modules\Core\Contracts\EmpresaRepositoryInterface;
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
        $this->assertInstanceOf(TenantContext::class, $this->app->make(TenantContextInterface::class));
        $this->assertInstanceOf(FeatureAccess::class, $this->app->make(FeatureAccessInterface::class));
        $this->assertInstanceOf(NotificationDispatcher::class, $this->app->make(NotificationDispatcherInterface::class));
        $this->assertSame(
            $this->app->make(TenantProvisionerRegistry::class),
            $this->app->make(TenantProvisionerInterface::class),
        );
    }

    public function test_contexto_sem_empresa_falha_em_seguranca(): void
    {
        $contexto = $this->app->make(TenantContextInterface::class);

        $this->assertNull($contexto->id());
        $this->assertFalse($contexto->hasTenant());

        $this->expectException(TenantNaoEncontradoException::class);
        $contexto->requireTenant();
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

    public function test_id_explicito_nao_permite_consultar_servico_sem_tenant(): void
    {
        $this->app->make(TenantManager::class)->clear();

        $empresas = \Mockery::mock(EmpresaRepositoryInterface::class);
        $empresas->shouldNotReceive('encontrarPorId');

        $acesso = new FeatureAccess(
            $this->app->make(TenantContextInterface::class),
            $empresas,
        );

        $this->assertFalse($acesso->servicoAtivo(Servico::Faturacao, 7));
    }
}
