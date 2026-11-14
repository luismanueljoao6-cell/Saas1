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
        $this->mock(RegistoEmpresaService::class)
            ->shouldReceive('registar')
            ->once()
            ->andReturnUsing(function (array $dadosEmpresa, array $dadosAdmin, ?array $servicos): User {
                $empresa = Empresa::create([
                    'nome_comercial' => $dadosEmpresa['nome_comercial'],
                    'nif' => $dadosEmpresa['nif'],
                ]);

                return User::create([
                    'empresa_id' => $empresa->id,
                    'name' => $dadosAdmin['name'],
                    'email' => $dadosAdmin['email'],
                    'password' => $dadosAdmin['password'],
                ]);
            });

        $sessaoAnterior = session()->getId();

        $resposta = $this->post('/registo', [
            'nome_comercial' => 'Empresa de Teste',
            'nif' => '450000002',
            'name' => 'Administrador',
            'email' => 'admin-registo-2@example.test',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
            'aceita_termos' => '1',
            'servicos' => ['faturacao'],
        ]);

        $utilizador = User::query()->where('email', 'admin-registo-2@example.test')->firstOrFail();
        $resposta->assertRedirect(route('core.empresa.editar', $utilizador->empresa_id));
        $this->assertAuthenticatedAs($utilizador, 'web');
        $this->assertNotSame($sessaoAnterior, session()->getId());
    }
}
