<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Tests\TestCase;

class LoginContaDesativadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_conta_desativada_com_palavra_passe_certa_conta_para_o_limitador(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa Desativada', 'nif' => '100000501']);

        User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Conta desativada',
            'email' => 'desativada@example.test',
            'password' => 'palavra-passe-certa',
            'ativo' => false,
        ]);

        $credenciais = ['email' => 'desativada@example.test', 'password' => 'palavra-passe-certa'];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', $credenciais)->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->post('/login', $credenciais)->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Demasiadas tentativas',
            (string) session('errors')->first('email'),
        );
        $this->assertGuest();
    }
}
