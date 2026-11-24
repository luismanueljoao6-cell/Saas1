<?php

namespace Modules\Core\Tests\Feature;

use InvalidArgumentException;
use Modules\Core\Services\TenantManager;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ComEmpresaTest extends TestCase
{
    public function test_define_a_empresa_durante_o_callback_e_repoe_o_estado(): void
    {
        $tenant = $this->app->make(TenantManager::class);

        $resultado = $tenant->comEmpresa(5, fn () => $tenant->id());

        $this->assertSame(5, $resultado);
        $this->assertNull($tenant->id());
    }

    public function test_repoe_a_empresa_anterior_e_aceita_aninhamento(): void
    {
        $tenant = $this->app->make(TenantManager::class);
        $tenant->set(1);

        $tenant->comEmpresa(2, function () use ($tenant): void {
            $this->assertSame(2, $tenant->id());

            $tenant->comEmpresa(3, fn () => $this->assertSame(3, $tenant->id()));

            $this->assertSame(2, $tenant->id());
        });

        $this->assertSame(1, $tenant->id());
    }

    public function test_sai_do_bypass_durante_o_callback_e_repoe_o_bypass(): void
    {
        $tenant = $this->app->make(TenantManager::class);

        $tenant->semTenant(function () use ($tenant): void {
            $this->assertTrue($tenant->emBypass());

            $tenant->comEmpresa(4, function () use ($tenant): void {
                $this->assertFalse($tenant->emBypass());
                $this->assertSame(4, $tenant->id());
            });

            $this->assertTrue($tenant->emBypass());
            $this->assertNull($tenant->id());
        });
    }

    public function test_repoe_o_estado_mesmo_com_excecao(): void
    {
        $tenant = $this->app->make(TenantManager::class);
        $tenant->set(1);

        try {
            $tenant->comEmpresa(2, fn () => throw new RuntimeException('falha'));
            $this->fail('A exceção devia ter sido propagada.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame(1, $tenant->id());
        $this->assertFalse($tenant->emBypass());
    }

    public function test_sincroniza_e_repoe_o_team_do_spatie(): void
    {
        $tenant = $this->app->make(TenantManager::class);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(9);

        $tenant->comEmpresa(2, fn () => $this->assertSame(2, $registrar->getPermissionsTeamId()));

        $this->assertSame(9, $registrar->getPermissionsTeamId());
    }

    public function test_recusa_id_de_empresa_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(TenantManager::class)->comEmpresa(0, fn () => null);
    }
}
