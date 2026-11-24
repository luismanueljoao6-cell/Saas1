<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * Documenta porque é que Tests\TestCase::actingAs() limpa a sessão ao trocar de
 * utilizador: sem isso, o AuthenticateSession (grupo 'web') desautentica o segundo.
 */
class TrocaDeUtilizadorNosTestesTest extends TestCase
{
    use RefreshDatabase;

    protected function utilizador(string $nif, string $email): User
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);

        return User::create([
            'empresa_id' => $empresa->id,
            'name' => $email,
            'email' => $email,
            'password' => 'Senha-Forte-123!',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->get('/_teste/quem-sou', fn () => 'id:'.auth()->id());
    }

    public function test_trocar_de_utilizador_a_meio_do_teste_nao_derruba_o_segundo(): void
    {
        $a = $this->utilizador('570000001', 'a@teste.ao');
        $b = $this->utilizador('570000002', 'b@teste.ao');

        $this->actingAs($a)->get('/_teste/quem-sou')->assertOk()->assertSee('id:'.$a->id);
        $this->actingAs($b)->get('/_teste/quem-sou')->assertOk()->assertSee('id:'.$b->id);
        $this->actingAs($a)->get('/_teste/quem-sou')->assertOk()->assertSee('id:'.$a->id);
    }

    public function test_o_mesmo_utilizador_continua_autenticado_entre_pedidos(): void
    {
        $a = $this->utilizador('570000003', 'c@teste.ao');

        $this->actingAs($a)->get('/_teste/quem-sou')->assertOk();
        $this->actingAs($a)->get('/_teste/quem-sou')->assertOk()->assertSee('id:'.$a->id);
    }

    public function test_sem_sessao_continua_a_ser_redirecionado_para_o_login(): void
    {
        $this->get('/_teste/quem-sou')->assertRedirect(route('core.login'));
    }
}
