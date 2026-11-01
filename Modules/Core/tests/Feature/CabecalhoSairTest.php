<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * O cabeçalho usava a rota 'logout', que não existe (chama-se 'core.logout'):
 * Route::has() dava falso e o botão "Sair" nunca era desenhado.
 */
class CabecalhoSairTest extends TestCase
{
    use RefreshDatabase;

    public function test_painel_mostra_o_formulario_de_sair(): void
    {
        $this->withoutVite();

        $empresa = Empresa::create(['nome_comercial' => 'Empresa Sair', 'nif' => '440000001']);
        $empresa->aderirServicos(['faturacao']);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Sair',
            'email' => 'sair@teste.test',
            'password' => bcrypt('segredo123'),
        ]);

        $this->actingAs($utilizador)
            ->get(route('core.painel'))
            ->assertOk()
            ->assertSee('action="'.route('core.logout').'"', false);
    }
}
