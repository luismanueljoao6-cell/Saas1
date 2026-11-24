<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Models\Convite;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Notifications\ConviteDeUtilizador;
use Modules\Core\Services\ConviteService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConvitesAdministracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    protected function criarEmpresa(string $nif): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
    }

    protected function criarUtilizador(Empresa $empresa, string $papel = 'Administrador'): User
    {
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Teste',
            'email' => uniqid('u').'@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
        $utilizador->assignRole(Role::firstOrCreate([
            'name' => $papel,
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
        ]));

        return $utilizador;
    }

    protected function emitir(User $admin, string $email): Convite
    {
        return app(ConviteService::class)->convidar($admin, $email, null, false);
    }

    public function test_administrador_emite_o_convite_sem_criar_utilizador(): void
    {
        $admin = $this->criarUtilizador($this->criarEmpresa('530000001'));

        $this->actingAs($admin)
            ->post(route('core.utilizadores.store'), [
                'name' => 'Novo Colega',
                'email' => 'Novo@Teste.ao',
                'administrador' => 1,
            ])
            ->assertSessionHas('sucesso');

        $this->assertDatabaseHas('convites', [
            'email' => 'novo@teste.ao',
            'empresa_id' => $admin->empresa_id,
            'administrador' => 1,
            'convidado_por' => $admin->id,
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'novo@teste.ao']);
        Notification::assertSentOnDemand(ConviteDeUtilizador::class);
    }

    public function test_email_ja_registado_e_recusado_na_validacao(): void
    {
        $admin = $this->criarUtilizador($this->criarEmpresa('530000002'));

        $this->actingAs($admin)
            ->post(route('core.utilizadores.store'), ['email' => $admin->email])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('convites', 0);
    }

    public function test_administrador_ve_os_convites_pendentes_e_nao_os_revogados(): void
    {
        $admin = $this->criarUtilizador($this->criarEmpresa('530000003'));
        $this->emitir($admin, 'pendente@teste.ao');
        $revogado = $this->emitir($admin, 'revogado@teste.ao');
        app(ConviteService::class)->revogar($admin, $revogado);

        $this->actingAs($admin)
            ->get(route('core.utilizadores.index'))
            ->assertOk()
            ->assertSee('pendente@teste.ao')
            ->assertDontSee('revogado@teste.ao');
    }

    public function test_administrador_revoga_o_convite(): void
    {
        $admin = $this->criarUtilizador($this->criarEmpresa('530000004'));
        $convite = $this->emitir($admin, 'novo@teste.ao');

        $this->actingAs($admin)
            ->post(route('core.convites.revogar', $convite))
            ->assertSessionHas('sucesso');

        $this->assertNotNull(Convite::withoutGlobalScopes()->find($convite->id)->revogado_em);
    }

    public function test_administrador_de_outra_empresa_nao_revoga_o_convite(): void
    {
        $adminA = $this->criarUtilizador($this->criarEmpresa('530000005'));
        $adminB = $this->criarUtilizador($this->criarEmpresa('530000006'));
        $convite = $this->emitir($adminA, 'novo@teste.ao');

        $resposta = $this->actingAs($adminB)->post(route('core.convites.revogar', $convite));

        $this->assertContains($resposta->getStatusCode(), [403, 404]);
        $this->assertNull(Convite::withoutGlobalScopes()->find($convite->id)->revogado_em);
    }

    public function test_utilizador_sem_papel_de_administrador_nao_revoga_nem_convida(): void
    {
        $empresa = $this->criarEmpresa('530000007');
        $admin = $this->criarUtilizador($empresa);
        $normal = $this->criarUtilizador($empresa, 'Utilizador');
        $convite = $this->emitir($admin, 'novo@teste.ao');

        $this->actingAs($normal)
            ->post(route('core.convites.revogar', $convite))
            ->assertForbidden();

        $this->actingAs($normal)
            ->post(route('core.utilizadores.store'), ['email' => 'outro@teste.ao'])
            ->assertForbidden();

        $this->assertNull(Convite::withoutGlobalScopes()->find($convite->id)->revogado_em);
        $this->assertDatabaseMissing('convites', ['email' => 'outro@teste.ao']);
    }
}
