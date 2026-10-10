<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Services\EquipaService;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EquipaPapeisTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_mesmo_papel_em_duas_empresas_nao_e_partilhado(): void
    {
        $servico = app(EquipaService::class);
        $papel = config('atelier.papel_costureira');

        $empresaA = Empresa::create(['nome_comercial' => 'Atelier A', 'nif' => '400000201']);
        $empresaB = Empresa::create(['nome_comercial' => 'Atelier B', 'nif' => '400000202']);

        $userA = User::create(['empresa_id' => $empresaA->id, 'name' => 'Ana', 'email' => 'ana@a.test', 'password' => bcrypt('segredo123')]);
        $userB = User::create(['empresa_id' => $empresaB->id, 'name' => 'Bia', 'email' => 'bia@b.test', 'password' => bcrypt('segredo123')]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresaA->id);
        $servico->atribuirPapel($userA, $papel);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresaB->id);
        $servico->atribuirPapel($userB, $papel);

        $papeis = Role::where('name', $papel)->get();

        $this->assertCount(2, $papeis, 'Cada empresa deve ter o seu proprio papel.');
        $this->assertEqualsCanonicalizing(
            [$empresaA->id, $empresaB->id],
            $papeis->pluck('empresa_id')->all()
        );
    }
}
