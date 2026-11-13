<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\RegistoEmpresaService;
use Tests\TestCase;

class RegistoSessaoSeguraTest extends TestCase
{
    use RefreshDatabase;

    public function test_registo_autentica_na_guard_web_e_regenera_a_sessao(): void
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa de Teste',
            'nif' => '450000001',
        ]);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Administrador',
            'email' => 'admin-registo@example.test',
            'password' => 'password-segura',
        ]);

        $this->mock(RegistoEmpresaService::class)
            ->shouldReceive('registar')
            ->once()
            ->andReturn($utilizador);

        $sessaoAnterior = session()->getId();

        $resposta = $this->post('/registo', [
            'nome_comercial' => 'Empresa de Teste',
            'nif' => '450000001',
            'name' => 'Administrador',
            'email' => 'admin-registo@example.test',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
            'aceita_termos' => '1',
            'servicos' => ['faturacao'],
        ]);

        $resposta->assertRedirect(route('core.empresa.editar', $empresa->id));
        $this->assertAuthenticatedAs($utilizador, 'web');
        $this->assertNotSame($sessaoAnterior, session()->getId());
    }
}
