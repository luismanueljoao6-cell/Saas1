<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Estes testes passam pela camada HTTP a sério (middlewares, rotas,
 * sessão) — ao contrário dos testes de serviço, que a contornam.
 */
class UtilizadoresEPapeisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function criarEmpresa(string $nif): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
    }

    protected function criarUtilizador(Empresa $empresa, ?string $papel = 'Administrador', bool $ativo = true): User
    {
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Teste',
            'email' => uniqid('u').'@teste.ao',
            'password' => 'Senha-Forte-123!',
            'ativo' => $ativo,
        ]);

        if ($papel) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
            $utilizador->assignRole(Role::firstOrCreate([
                'name' => $papel,
                'guard_name' => 'web',
                'empresa_id' => $empresa->id,
            ]));
        }

        return $utilizador;
    }

    protected function definirLimiteUtilizadores(?int $limite): void
    {
        $this->app->bind(LimitesDaEmpresa::class, fn () => new class($limite) implements LimitesDaEmpresa
        {
            public function __construct(protected ?int $limite)
            {
            }

            public function limite(Empresa $empresa, string $chave): ?int
            {
                return $chave === 'max_utilizadores' ? $this->limite : null;
            }
        });
    }

    protected function dadosNovoUtilizador(): array
    {
        return [
            'name' => 'Novo Colega',
            'email' => 'novo@teste.ao',
            'password' => 'Senha-Forte-123!',
            'password_confirmation' => 'Senha-Forte-123!',
        ];
    }

    public function test_utilizador_ativo_acede_ao_painel(): void
    {
        $utilizador = $this->criarUtilizador($this->criarEmpresa('500000001'));

        $this->actingAs($utilizador)->get('/painel')->assertOk();
    }

    public function test_utilizador_desativado_perde_o_acesso_imediatamente(): void
    {
        $utilizador = $this->criarUtilizador($this->criarEmpresa('500000002'), 'Utilizador', ativo: false);

        $this->actingAs($utilizador)
            ->get('/painel')
            ->assertRedirect(route('core.login'));

        $this->assertGuest();
    }

    public function test_limite_de_utilizadores_do_plano_e_respeitado(): void
    {
        $admin = $this->criarUtilizador($this->criarEmpresa('500000003'));
        $this->definirLimiteUtilizadores(1); // o próprio admin já ocupa a única vaga

        $this->actingAs($admin)
            ->post(route('core.utilizadores.store'), $this->dadosNovoUtilizador())
            ->assertSessionHas('erro');

        $this->assertDatabaseMissing('users', ['email' => 'novo@teste.ao']);
    }

    public function test_sem_limite_o_utilizador_e_criado(): void
    {
        $admin = $this->criarUtilizador($this->criarEmpresa('500000004'));
        $this->definirLimiteUtilizadores(null);

        $this->actingAs($admin)
            ->post(route('core.utilizadores.store'), $this->dadosNovoUtilizador())
            ->assertSessionHas('sucesso');

        $this->assertDatabaseHas('users', ['email' => 'novo@teste.ao', 'empresa_id' => $admin->empresa_id]);
    }

    public function test_utilizador_sem_papel_de_administrador_nao_cria_utilizadores(): void
    {
        $normal = $this->criarUtilizador($this->criarEmpresa('500000005'), 'Utilizador');

        $this->actingAs($normal)
            ->post(route('core.utilizadores.store'), $this->dadosNovoUtilizador())
            ->assertForbidden();
    }

    public function test_administrador_nao_desativa_utilizador_de_outra_empresa(): void
    {
        $adminA = $this->criarUtilizador($this->criarEmpresa('500000006'));
        $alvoB = $this->criarUtilizador($this->criarEmpresa('500000007'), 'Utilizador');

        $this->actingAs($adminA)
            ->post(route('core.utilizadores.alternar-ativo', $alvoB))
            ->assertForbidden();

        $this->assertTrue((bool) $alvoB->fresh()->ativo);
    }
}
