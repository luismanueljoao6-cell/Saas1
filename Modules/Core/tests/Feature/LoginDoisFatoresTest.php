<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Http\Controllers\Auth\DesafioDoisFatoresController;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\DoisFatoresService;
use Tests\TestCase;

/**
 * Liga o /login ao desafio de 2FA. O desafio em si está em DesafioDoisFatoresTest.
 */
class LoginDoisFatoresTest extends TestCase
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

    protected function servico(): DoisFatoresService
    {
        return app(DoisFatoresService::class);
    }

    /** @return array{0: User, 1: string} utilizador e segredo */
    protected function comDoisFatores(): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa 560000001', 'nif' => '560000001']);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Dois Fatores',
            'email' => 'dois@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        $segredo = $this->servico()->prepararAtivacao($utilizador);
        $codigos = $this->servico()->confirmarAtivacao($utilizador, $this->codigoNoPasso($segredo));

        $this->assertNotNull($codigos);

        return [$utilizador->fresh(), $segredo];
    }

    protected function codigoNoPasso(string $segredo, int $deslocamento = 0): string
    {
        return $this->servico()->codigo($segredo, $this->servico()->passoDe(now()->timestamp) + $deslocamento);
    }

    protected function credenciais(array $extra = []): array
    {
        return ['email' => 'dois@teste.ao', 'password' => 'Senha-Forte-123!', ...$extra];
    }

    public function test_palavra_passe_errada_nao_cria_estado_de_desafio(): void
    {
        $this->comDoisFatores();

        $this->post('/login', $this->credenciais(['password' => 'errada']))
            ->assertSessionHasErrors('email');

        $this->assertNull(session(DesafioDoisFatoresController::CHAVE_SESSAO));
        $this->assertGuest();
    }

    public function test_conta_desativada_com_2fa_nao_chega_ao_desafio(): void
    {
        [$utilizador] = $this->comDoisFatores();
        $utilizador->forceFill(['ativo' => false])->save();

        $this->post('/login', $this->credenciais())->assertSessionHasErrors('email');

        $this->assertNull(session(DesafioDoisFatoresController::CHAVE_SESSAO));
        $this->assertGuest();
    }

    public function test_o_estado_pendente_guarda_utilizador_validade_e_lembrar(): void
    {
        [$utilizador] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais(['lembrar' => '1']))
            ->assertRedirect(route('core.2fa.desafio'));

        $estado = session(DesafioDoisFatoresController::CHAVE_SESSAO);

        $this->assertEquals($utilizador->id, $estado['id']);
        $this->assertTrue($estado['lembrar']);
        $this->assertSame(
            now()->addMinutes(DesafioDoisFatoresController::VALIDADE_MINUTOS)->timestamp,
            $estado['ate'],
        );
        $this->assertGuest();
    }

    public function test_o_pedido_de_lembrar_sobrevive_ao_desafio(): void
    {
        [$utilizador, $segredo] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais(['lembrar' => '1']));

        $this->post(route('core.2fa.desafio.store'), ['codigo' => $this->codigoNoPasso($segredo, 1)])
            ->assertRedirect(route('core.painel'));

        $this->assertAuthenticatedAs($utilizador);
        $this->assertNotNull($utilizador->fresh()->remember_token);
    }

    public function test_sem_lembrar_nao_fica_cookie_de_sessao_prolongada(): void
    {
        [$utilizador, $segredo] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais());

        $this->post(route('core.2fa.desafio.store'), ['codigo' => $this->codigoNoPasso($segredo, 1)])
            ->assertRedirect(route('core.painel'));

        $this->assertAuthenticatedAs($utilizador);
        $this->assertNull($utilizador->fresh()->remember_token);
    }
}
