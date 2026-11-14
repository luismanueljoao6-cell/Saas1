<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Armazem\Support\PapeisArmazem;
use Modules\Core\Models\Empresa;
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
}
