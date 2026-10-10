<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\User;
use Modules\Core\Services\RegistoEmpresaService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProvisionamentoPapeisTest extends TestCase
{
    use RefreshDatabase;

    protected function registar(string $nif, string $email, array $servicos): User
    {
        return app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif],
            ['name' => 'Admin '.$nif, 'email' => $email, 'password' => 'Senha-segura-123'],
            $servicos,
        );
    }

    protected function pode(User $utilizador, string $permissao): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($utilizador->empresa_id);
        $registrar->forgetCachedPermissions();
        $utilizador->unsetRelation('roles')->unsetRelation('permissions');

        return $utilizador->can($permissao);
    }

    public function test_cada_empresa_nova_com_estudio_recebe_os_seus_papeis_com_permissoes(): void
    {
        $adminA = $this->registar('510000001', 'a@example.test', ['estudio']);
        $adminB = $this->registar('510000002', 'b@example.test', ['estudio']);

        $this->assertTrue($this->pode($adminA, 'estudiomusica.gerir-salas'));
        $this->assertTrue($this->pode($adminB, 'estudiomusica.gerir-salas'));

        foreach ([$adminA, $adminB] as $admin) {
            $this->assertTrue(Role::where(['name' => 'Recepcao', 'empresa_id' => $admin->empresa_id])->exists());
            $this->assertTrue(Role::where(['name' => 'Engenheiro', 'empresa_id' => $admin->empresa_id])->exists());
        }

        $this->assertSame(2, Role::where('name', 'Administrador')->count());
    }

    public function test_empresa_sem_estudio_nao_recebe_os_papeis_do_estudio(): void
    {
        $admin = $this->registar('510000003', 'c@example.test', ['atelier']);

        $this->assertFalse(Role::where(['name' => 'Recepcao', 'empresa_id' => $admin->empresa_id])->exists());
        $this->assertFalse($this->pode($admin, 'estudiomusica.gerir-salas'));
    }

    public function test_ativar_o_estudio_mais_tarde_provisiona_os_papeis(): void
    {
        $admin = $this->registar('510000004', 'd@example.test', ['atelier']);

        $this->actingAs($admin)
            ->put(route('core.servicos.atualizar'), ['servicos' => ['atelier', 'estudio']])
            ->assertRedirect();

        $this->assertTrue(Role::where(['name' => 'Recepcao', 'empresa_id' => $admin->empresa_id])->exists());
        $this->assertTrue($this->pode($admin, 'estudiomusica.gerir-salas'));
    }

    public function test_administrador_atribui_rececao_e_so_o_administrador_pode(): void
    {
        $admin = $this->registar('510000005', 'e@example.test', ['estudio']);

        $colega = User::create([
            'empresa_id' => $admin->empresa_id,
            'name' => 'Colega',
            'email' => 'colega@example.test',
            'password' => Hash::make('Senha-segura-123'),
        ]);

        $this->actingAs($admin)
            ->post(route('estudiomusica.equipa.atribuir'), ['utilizador_id' => $colega->id, 'papel' => 'Recepcao'])
            ->assertRedirect();

        $this->assertTrue($this->pode($colega, 'estudiomusica.gerir-sessoes'));
        $this->assertFalse($this->pode($colega, 'estudiomusica.gerir-salas'));

        $this->actingAs($colega)
            ->post(route('estudiomusica.equipa.atribuir'), ['utilizador_id' => $admin->id, 'papel' => 'Engenheiro'])
            ->assertForbidden();
    }
}
