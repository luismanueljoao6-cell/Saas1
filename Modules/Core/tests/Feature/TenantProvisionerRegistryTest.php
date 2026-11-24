<?php

namespace Modules\\Core\\Tests\\Feature;

use Modules\\Core\\Services\\TenantManager;
use Modules\\Core\\Services\\TenantProvisionerRegistry;
use Spatie\\Permission\\PermissionRegistrar;
use Tests\\TestCase;

class TenantProvisionerRegistryTest extends TestCase
{
    public function test_provisionador_executa_com_tenant_e_team_spatie_e_restaura_contexto(): void
    {
        $tenant = app(TenantManager::class);
        $registrar = app(PermissionRegistrar::class);
        $observado = null;

        $tenant->comEmpresa(10, function () use ($tenant, $registrar, &$observado): void {
            $registrar->setPermissionsTeamId(10);

            $registry = app(TenantProvisionerRegistry::class);
            $registry->register('teste', function (int $empresaId) use ($tenant, $registrar, &$observado): void {
                $observado = [
                    'empresa_id' => $empresaId,
                    'tenant_id' => $tenant->id(),
                    'bypass' => $tenant->emBypass(),
                    'team_id' => $registrar->getPermissionsTeamId(),
                ];
            });

            $registry->provision(20, ['teste']);

            $this->assertSame(10, $tenant->id());
            $this->assertSame(10, $registrar->getPermissionsTeamId());
            $this->assertFalse($tenant->emBypass());
        });

        $this->assertSame([
            'empresa_id' => 20,
            'tenant_id' => 20,
            'bypass' => false,
            'team_id' => 20,
        ], $observado);
        $this->assertNull($tenant->id());
        $this->assertNull($registrar->getPermissionsTeamId());
    }

    public function test_provisionador_nao_e_executado_para_servico_inativo(): void
    {
        $executado = false;
        $registry = app(TenantProvisionerRegistry::class);
        $registry->register('estudio', function () use (&$executado): void {
            $executado = true;
        });

        $registry->provision(20, ['atelier']);

        $this->assertFalse($executado);
    }
}
