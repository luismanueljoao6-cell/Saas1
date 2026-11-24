<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\DoisFatoresService;
use Tests\TestCase;

class DesafioDoisFatoresLimiteUtilizadorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-11-23 12:00:10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: User, 1: string} utilizador com 2FA ativo e o respetivo segredo */
    protected function comDoisFatores(): array
    {
        $servico = app(DoisFatoresService::class);
        $empresa = Empresa::create(['nome_comercial' => 'Empresa 550000002', 'nif' => '550000002']);
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Teste',
            'email' => 'u@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        $segredo = $servico->prepararAtivacao($utilizador);
        $codigos = $servico->confirmarAtivacao($utilizador, $servico->codigo($segredo, $servico->passoDe(now()->timestamp)));
        $this->assertNotNull($codigos);

        return [$utilizador->fresh(), $segredo];
    }

    public function test_rodar_de_ip_nao_contorna_o_limite_por_utilizador(): void
    {
        [, $segredo] = $this->comDoisFatores();
        $servico = app(DoisFatoresService::class);

        $this->post('/login', ['email' => 'u@teste.ao', 'password' => 'Senha-Forte-123!']);

        // 15 falhas, cada uma de um IP diferente: nenhum IP chega às 5 do limite por IP.
        for ($i = 1; $i <= 15; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post(route('core.2fa.desafio.store'), ['codigo' => '000000'])
                ->assertSessionHasErrors('codigo');
        }

        // IP novo e código CERTO: continua bloqueado pelo limite do utilizador.
        $certo = $servico->codigo($segredo, $servico->passoDe(now()->timestamp) + 1);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(route('core.2fa.desafio.store'), ['codigo' => $certo])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
        $this->assertStringContainsString('Demasiadas tentativas', (string) session('errors')->first('codigo'));
    }

    public function test_abaixo_do_limite_o_codigo_certo_continua_a_entrar(): void
    {
        [$utilizador, $segredo] = $this->comDoisFatores();
        $servico = app(DoisFatoresService::class);

        $this->post('/login', ['email' => 'u@teste.ao', 'password' => 'Senha-Forte-123!']);

        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post(route('core.2fa.desafio.store'), ['codigo' => '000000'])
                ->assertSessionHasErrors('codigo');
        }

        $certo = $servico->codigo($segredo, $servico->passoDe(now()->timestamp) + 1);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(route('core.2fa.desafio.store'), ['codigo' => $certo])
            ->assertRedirect(route('core.painel'));

        $this->assertAuthenticatedAs($utilizador);
    }
}
