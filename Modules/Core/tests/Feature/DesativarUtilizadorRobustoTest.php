<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Desativar utilizadores sem deixar a empresa sem administrador e sem deixar
 * credenciais de API vivas. Complementa UtilizadoresEPapeisTest.
 */
class DesativarUtilizadorRobustoTest extends TestCase
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

    protected function criarUtilizador(Empresa $empresa, ?string $papel = 'Administrador'): User
    {
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Teste',
            'email' => uniqid('u').'@teste.ao',
            'password' => 'Senha-Forte-123!',
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

    public function test_administrador_desativado_entretanto_nao_desativa_o_colega(): void
    {
        $empresa = $this->criarEmpresa('591000001');
        $a = $this->criarUtilizador($empresa);
        $b = $this->criarUtilizador($empresa);

        // Pedido em voo: o A desativou o B noutro pedido, mas a sessão do B
        // (instância em memória) ainda o dá como ativo e passa o middleware.
        User::query()->whereKey($b->id)->update(['ativo' => false]);

        $this->actingAs($b)
            ->post(route('core.utilizadores.alternar-ativo', $a), ['ativo' => '0'])
            ->assertForbidden();

        $this->assertTrue((bool) $a->fresh()->ativo);
    }

    public function test_desativar_um_utilizador_revoga_os_seus_tokens_de_api(): void
    {
        $empresa = $this->criarEmpresa('591000002');
        $admin = $this->criarUtilizador($empresa);
        $alvo = $this->criarUtilizador($empresa, 'Utilizador');
        $alvo->createToken('integracao');
        $this->assertSame(1, $alvo->tokens()->count());

        $this->actingAs($admin)
            ->post(route('core.utilizadores.alternar-ativo', $alvo), ['ativo' => '0'])
            ->assertSessionHas('sucesso');

        $this->assertFalse((bool) $alvo->fresh()->ativo);
        $this->assertSame(0, $alvo->tokens()->count());
    }

    public function test_pedido_para_ativar_quem_ja_esta_ativo_nao_mexe_nos_tokens(): void
    {
        $empresa = $this->criarEmpresa('591000003');
        $admin = $this->criarUtilizador($empresa);
        $alvo = $this->criarUtilizador($empresa, 'Utilizador');
        $alvo->createToken('integracao');

        $this->actingAs($admin)
            ->post(route('core.utilizadores.alternar-ativo', $alvo), ['ativo' => '1'])
            ->assertSessionHas('sucesso');

        $this->assertTrue((bool) $alvo->fresh()->ativo);
        $this->assertSame(1, $alvo->tokens()->count());
    }

    public function test_um_administrador_ativo_continua_a_poder_desativar_o_colega(): void
    {
        $empresa = $this->criarEmpresa('591000004');
        $a = $this->criarUtilizador($empresa);
        $b = $this->criarUtilizador($empresa);

        $this->actingAs($a)
            ->post(route('core.utilizadores.alternar-ativo', $b), ['ativo' => '0'])
            ->assertSessionHas('sucesso');

        $this->assertFalse((bool) $b->fresh()->ativo);
        $this->assertTrue((bool) $a->fresh()->ativo);
    }
}
