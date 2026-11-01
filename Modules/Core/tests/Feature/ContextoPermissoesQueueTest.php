<?php

namespace Modules\\Core\\Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Queue\\Events\\JobFailed;
use Illuminate\\Queue\\Events\\JobProcessed;
use Illuminate\\Queue\\Events\\JobProcessing;
use Illuminate\\Support\\Facades\\Event;
use Modules\\Core\\Services\\TenantManager;
use Mockery;
use RuntimeException;
use Spatie\\Permission\\PermissionRegistrar;
use Illuminate\\Contracts\\Queue\\Job;
use Tests\\TestCase;

class ContextoPermissoesQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_contextos_tenant_e_spatie_sao_limpos_entre_jobs_persistentes(): void
    {
        $tenant = app(TenantManager::class);
        $permissoes = app(PermissionRegistrar::class);

        $tenant->set(42);
        $permissoes->setPermissionsTeamId(42);

        Event::dispatch(new JobProcessing('redis', Mockery::mock(Job::class)));

        $this->assertFalse($tenant->has());
        $this->assertNull($permissoes->getPermissionsTeamId());

        $tenant->set(84);
        $permissoes->setPermissionsTeamId(84);

        Event::dispatch(new JobProcessed('redis', Mockery::mock(Job::class)));

        $this->assertFalse($tenant->has());
        $this->assertNull($permissoes->getPermissionsTeamId());

        $tenant->set(126);
        $permissoes->setPermissionsTeamId(126);

        Event::dispatch(new JobFailed(
            'redis',
            Mockery::mock(Job::class),
            new RuntimeException('Falha simulada')
        ));

        $this->assertFalse($tenant->has());
        $this->assertNull($permissoes->getPermissionsTeamId());
    }

    public function test_jobs_sync_nao_limpa_contexto_do_pedido_atual(): void
    {
        $tenant = app(TenantManager::class);
        $permissoes = app(PermissionRegistrar::class);

        $tenant->set(42);
        $permissoes->setPermissionsTeamId(42);

        Event::dispatch(new JobProcessing('sync', Mockery::mock(Job::class)));

        $this->assertSame(42, $tenant->id());
        $this->assertSame(42, $permissoes->getPermissionsTeamId());
    }
}
