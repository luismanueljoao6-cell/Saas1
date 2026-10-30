<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Tests\TestCase;

class ApiSanctumTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_invalido_devolve_401_e_nao_rebenta_com_500(): void
    {
        $this->withHeader('Authorization', 'Bearer 1|token-invalido')
            ->getJson('/api/core/empresa')
            ->assertUnauthorized();
    }

    public function test_token_valido_devolve_a_empresa_do_utilizador(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa API', 'nif' => '430000001']);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador API',
            'email' => 'api@teste.test',
            'password' => bcrypt('segredo123'),
        ]);

        $token = $utilizador->createToken('teste')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/core/empresa')
            ->assertOk()
            ->assertJsonPath('nif', '430000001');
    }
}
