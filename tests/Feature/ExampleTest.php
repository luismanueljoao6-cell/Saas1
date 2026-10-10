<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Criar conta')
            ->assertSee('Atelier de Costura');
    }

    public function test_utilizador_com_sessao_vai_direto_ao_painel(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa Teste', 'nif' => '500000123']);
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Teste',
            'email' => 'u500000123@teste.test',
            'password' => 'Senha-Forte-123!',
            'ativo' => true,
        ]);

        $this->actingAs($utilizador)->get('/')->assertRedirect(route('core.painel'));
    }
}
