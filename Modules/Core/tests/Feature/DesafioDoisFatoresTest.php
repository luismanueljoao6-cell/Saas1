<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Http\Controllers\Auth\DesafioDoisFatoresController;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\DoisFatoresService;
use Tests\TestCase;

class DesafioDoisFatoresTest extends TestCase
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

    protected function utilizador(): User
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa 550000001', 'nif' => '550000001']);

        return User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Teste',
            'email' => 'u@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);
    }

    /** @return array{0: User, 1: string, 2: list<string>} utilizador, segredo e códigos de recuperação */
    protected function comDoisFatores(): array
    {
        $utilizador = $this->utilizador();
        $segredo = $this->servico()->prepararAtivacao($utilizador);
        $codigos = $this->servico()->confirmarAtivacao($utilizador, $this->codigoNoPasso($segredo));

        $this->assertNotNull($codigos);

        return [$utilizador->fresh(), $segredo, $codigos];
    }

    protected function codigoNoPasso(string $segredo, int $deslocamento = 0): string
    {
        return $this->servico()->codigo($segredo, $this->servico()->passoDe(now()->timestamp) + $deslocamento);
    }

    protected function credenciais(): array
    {
        return ['email' => 'u@teste.ao', 'password' => 'Senha-Forte-123!'];
    }

    public function test_conta_sem_2fa_entra_diretamente(): void
    {
        $utilizador = $this->utilizador();

        $this->post('/login', $this->credenciais())->assertRedirect(route('core.painel'));

        $this->assertAuthenticatedAs($utilizador);
    }

    public function test_conta_com_2fa_nao_fica_autenticada_ate_ao_desafio(): void
    {
        $this->comDoisFatores();

        $this->post('/login', $this->credenciais())->assertRedirect(route('core.2fa.desafio'));

        $this->assertGuest();
        $this->get(route('core.2fa.desafio'))->assertOk();
        $this->get('/painel')->assertRedirect();
    }

    public function test_desafio_sem_passar_pela_palavra_passe_volta_ao_login(): void
    {
        $this->get(route('core.2fa.desafio'))->assertRedirect(route('core.login'));

        $this->post(route('core.2fa.desafio.store'), ['codigo' => '123456'])
            ->assertRedirect(route('core.login'));

        $this->assertGuest();
    }

    public function test_codigo_da_app_certo_autentica(): void
    {
        [$utilizador, $segredo] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais());

        $this->post(route('core.2fa.desafio.store'), ['codigo' => $this->codigoNoPasso($segredo, 1)])
            ->assertRedirect(route('core.painel'));

        $this->assertAuthenticatedAs($utilizador);
        $this->assertNull(session(DesafioDoisFatoresController::CHAVE_SESSAO));
    }

    public function test_codigo_errado_nao_autentica(): void
    {
        $this->comDoisFatores();

        $this->post('/login', $this->credenciais());

        $this->post(route('core.2fa.desafio.store'), ['codigo' => '000000'])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
    }

    public function test_codigo_de_recuperacao_autentica_e_gasta_se(): void
    {
        [$utilizador, , $codigos] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais());

        $this->post(route('core.2fa.desafio.store'), ['codigo' => $codigos[0]])
            ->assertRedirect(route('core.painel'))
            ->assertSessionHas('sucesso');

        $this->assertAuthenticatedAs($utilizador);
        $this->assertSame(7, $this->servico()->codigosRestantes($utilizador->fresh()));
    }

    public function test_cinco_erros_bloqueiam_mesmo_com_o_codigo_certo(): void
    {
        [, $segredo] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais());

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('core.2fa.desafio.store'), ['codigo' => '000000'])
                ->assertSessionHasErrors('codigo');
        }

        $this->post(route('core.2fa.desafio.store'), ['codigo' => $this->codigoNoPasso($segredo, 1)])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
        $this->assertStringContainsString('Demasiadas tentativas', (string) session('errors')->first('codigo'));
    }

    public function test_o_mesmo_codigo_nao_serve_duas_vezes(): void
    {
        [, $segredo] = $this->comDoisFatores();
        $codigo = $this->codigoNoPasso($segredo, 1);

        $this->post('/login', $this->credenciais());
        $this->post(route('core.2fa.desafio.store'), ['codigo' => $codigo])
            ->assertRedirect(route('core.painel'));

        $this->app['auth']->guard('web')->logout();

        $this->post('/login', $this->credenciais())->assertRedirect(route('core.2fa.desafio'));
        $this->post(route('core.2fa.desafio.store'), ['codigo' => $codigo])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
    }

    public function test_conta_desativada_entre_a_palavra_passe_e_o_codigo_nao_entra(): void
    {
        [$utilizador, $segredo] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais());
        $utilizador->forceFill(['ativo' => false])->save();

        $this->post(route('core.2fa.desafio.store'), ['codigo' => $this->codigoNoPasso($segredo, 1)])
            ->assertRedirect(route('core.login'));

        $this->assertGuest();
    }

    public function test_o_desafio_expira_ao_fim_de_dez_minutos(): void
    {
        [, $segredo] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais());
        $codigo = $this->codigoNoPasso($segredo, 1);

        Carbon::setTestNow(now()->addMinutes(11));

        $this->post(route('core.2fa.desafio.store'), ['codigo' => $codigo])
            ->assertRedirect(route('core.login'));

        $this->assertGuest();
    }
}
