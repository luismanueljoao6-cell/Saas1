<?php

namespace Modules\Core\Tests\Feature;

use Modules\Core\Services\TenantManager;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SemTenantTest extends TestCase
{
    public function test_remove_o_team_do_spatie_durante_operacoes_globais_e_repoe_o_contexto(): void
    {
        $tenant = $this->app->make(TenantManager::class);
        $registrar = $this->app->make(PermissionRegistrar::class);

        $tenant->set(11);
        $registrar->setPermissionsTeamId(11);

        $tenant->semTenant(function () use ($tenant, $registrar): void {
            $this->assertNull($tenant->id());
            $this->assertFalse($tenant->has());
            $this->assertTrue($tenant->emBypass());
            $this->assertNull($registrar->getPermissionsTeamId());
        });

        $this->assertSame(11, $tenant->id());
        $this->assertFalse($tenant->emBypass());
        $this->assertSame(11, $registrar->getPermissionsTeamId());
    }

    public function test_sem_tenant_e_com_empresa_aninhados_repoem_todos_os_contextos(): void
    {
        $tenant = $this->app->make(TenantManager::class);
        $registrar = $this->app->make(PermissionRegistrar::class);

        $tenant->set(11);
        $registrar->setPermissionsTeamId(11);

        $tenant->semTenant(function () use ($tenant, $registrar): void {
            $this->assertNull($registrar->getPermissionsTeamId());

            $tenant->comEmpresa(22, function () use ($tenant, $registrar): void {
                $this->assertSame(22, $tenant->id());
                $this->assertSame(22, $registrar->getPermissionsTeamId());

                $tenant->semTenant(function () use ($tenant, $registrar): void {
                    $this->assertNull($tenant->id());
                    $this->assertTrue($tenant->emBypass());
                    $this->assertNull($registrar->getPermissionsTeamId());
                });

                $this->assertSame(22, $tenant->id());
                $this->assertFalse($tenant->emBypass());
                $this->assertSame(22, $registrar->getPermissionsTeamId());
            });

            $this->assertNull($tenant->id());
            $this->assertTrue($tenant->emBypass());
            $this->assertNull($registrar->getPermissionsTeamId());
        });

        $this->assertSame(11, $tenant->id());
        $this->assertFalse($tenant->emBypass());
        $this->assertSame(11, $registrar->getPermissionsTeamId());
    }

    public function test_restaura_tenant_bypass_e_team_do_spatie_quando_callback_lanca_excecao(): void
    {
        $tenant = $this->app->make(TenantManager::class);
        $registrar = $this->app->make(PermissionRegistrar::class);

        $tenant->set(7);
        $registrar->setPermissionsTeamId(7);

        try {
            $tenant->semTenant(function () use ($tenant, $registrar): void {
                $this->assertNull($tenant->id());
                $this->assertTrue($tenant->emBypass());
                $this->assertNull($registrar->getPermissionsTeamId());

                throw new RuntimeException('falha simulada');
            });

            $this->fail('A exceção devia ter sido propagada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('falha simulada', $exception->getMessage());
        }

        $this->assertSame(7, $tenant->id());
        $this->assertFalse($tenant->emBypass());
        $this->assertSame(7, $registrar->getPermissionsTeamId());
    }

    public function test_preserva_team_nulo_antes_e_depois_do_contexto_global(): void
    {
        $tenant = $this->app->make(TenantManager::class);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);

        $tenant->semTenant(function () use ($registrar): void {
            $this->assertNull($registrar->getPermissionsTeamId());
        });

        $this->assertNull($registrar->getPermissionsTeamId());
    }
}
