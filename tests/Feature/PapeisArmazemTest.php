<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Armazem\Support\PapeisArmazem;
use Modules\Core\Models\Empresa;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PapeisArmazemTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisiona_permissoes_para_administrador_de_cada_empresa(): void
    {
        $empresaA = Empresa::create([
            'nome_comercial' => 'Empresa Armazem A',
            'nif' => '630200101',
        ]);
        $empresaB = Empresa::create([
            'nome_comercial' => 'Empresa Armazem B',
            'nif' => '630200102',
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(999);
        PapeisArmazem::provisionar((int) $empresaA->id);
        PapeisArmazem::provisionar((int) $empresaB->id);

        $this->assertSame(999, $registrar->getPermissionsTeamId());

        $registrar->setPermissionsTeamId((int) $empresaA->id);
        $adminA = Role::query()
            ->where('empresa_id', $empresaA->id)
            ->where('name', 'Administrador')
            ->firstOrFail();

        $this->assertEqualsCanonicalizing(
            PapeisArmazem::PERMISSOES,
            $adminA->permissions()->pluck('name')->all(),
        );

        $registrar->setPermissionsTeamId((int) $empresaB->id);
        $adminB = Role::query()
            ->where('empresa_id', $empresaB->id)
            ->where('name', 'Administrador')
            ->firstOrFail();

        $this->assertNotSame($adminA->id, $adminB->id);
        $this->assertEqualsCanonicalizing(
            PapeisArmazem::PERMISSOES,
            $adminB->permissions()->pluck('name')->all(),
        );
    }

    public function test_provisionamento_nao_remove_permissoes_de_outros_modulos(): void
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa Permissoes Preservadas',
            'nif' => '630200103',
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $empresa->id);

        $permissaoFaturacao = Permission::firstOrCreate([
            'name' => 'faturacao.documentos.ver',
            'guard_name' => 'web',
        ]);
        $administrador = Role::firstOrCreate([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
        ]);
        $administrador->givePermissionTo($permissaoFaturacao);

        PapeisArmazem::provisionar((int) $empresa->id);

        $registrar->setPermissionsTeamId((int) $empresa->id);
        $administrador->refresh();

        $this->assertTrue($administrador->hasPermissionTo($permissaoFaturacao));
        $this->assertTrue($administrador->hasPermissionTo('armazem.inventarios.aprovar'));
        $this->assertTrue($administrador->hasPermissionTo('armazem.inventarios.rejeitar'));
    }
}
