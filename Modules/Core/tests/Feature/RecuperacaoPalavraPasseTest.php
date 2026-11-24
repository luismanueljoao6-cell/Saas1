<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Notifications\RedefinirPalavraPasse;
use Tests\TestCase;

class RecuperacaoPalavraPasseTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAGEM_PEDIDO = 'Se existir uma conta ativa com esse e-mail, enviámos a ligação para redefinir a palavra-passe.';

    private static int $contador = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function criarUtilizador(bool $ativo = true): User
    {
        $n = ++self::$contador;

        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa Recuperação '.$n,
            'nif' => '52'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        ]);

        return User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador '.$n,
            'email' => "recuperacao{$n}@example.test",
            'password' => 'Senha-Antiga-123!',
            'ativo' => $ativo,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function dadosDeRedefinicao(User $utilizador, string $token, string $palavraPasse = 'Nova-Senha-456!'): array
    {
        return [
            'token' => $token,
            'email' => $utilizador->email,
            'password' => $palavraPasse,
            'password_confirmation' => $palavraPasse,
        ];
    }

    public function test_paginas_de_recuperacao_abrem_a_visitantes(): void
    {
        $this->get('/esqueci-palavra-passe')->assertOk();
        $this->get('/redefinir-palavra-passe/um-token?email=alguem@example.test')->assertOk();
    }

    public function test_pedido_envia_a_ligacao_a_conta_ativa(): void
    {
        Notification::fake();
        $utilizador = $this->criarUtilizador();

        $this->post('/esqueci-palavra-passe', ['email' => $utilizador->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', self::MENSAGEM_PEDIDO);

        Notification::assertSentTo($utilizador, RedefinirPalavraPasse::class);
    }

    public function test_pedido_nao_revela_se_a_conta_existe(): void
    {
        Notification::fake();
        $this->criarUtilizador();

        $this->post('/esqueci-palavra-passe', ['email' => 'ninguem@example.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::MENSAGEM_PEDIDO);

        Notification::assertNothingSent();
    }

    public function test_conta_desativada_nao_recebe_ligacao_mas_a_resposta_e_igual(): void
    {
        Notification::fake();
        $desativada = $this->criarUtilizador(ativo: false);

        $this->post('/esqueci-palavra-passe', ['email' => $desativada->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::MENSAGEM_PEDIDO);

        Notification::assertNothingSent();
    }

    public function test_redefinicao_valida_altera_a_palavra_passe_e_revoga_acessos(): void
    {
        $utilizador = $this->criarUtilizador();
        $utilizador->forceFill(['remember_token' => 'token-antigo'])->save();
        $utilizador->createToken('integracao');
        $this->assertSame(1, $utilizador->tokens()->count());
        $this->assertNull($utilizador->email_verified_at);

        $token = Password::createToken($utilizador);

        $this->post('/redefinir-palavra-passe', $this->dadosDeRedefinicao($utilizador, $token))
            ->assertRedirect(route('core.login'))
            ->assertSessionHasNoErrors();

        $utilizador->refresh();

        $this->assertTrue(Hash::check('Nova-Senha-456!', $utilizador->password));
        $this->assertNotSame('token-antigo', $utilizador->remember_token);
        $this->assertSame(0, $utilizador->tokens()->count());
        $this->assertNotNull($utilizador->email_verified_at);
        $this->assertGuest();
    }

    public function test_o_token_so_pode_ser_usado_uma_vez(): void
    {
        $utilizador = $this->criarUtilizador();
        $token = Password::createToken($utilizador);

        $this->post('/redefinir-palavra-passe', $this->dadosDeRedefinicao($utilizador, $token))
            ->assertSessionHasNoErrors();

        $this->post('/redefinir-palavra-passe', $this->dadosDeRedefinicao($utilizador, $token, 'Outra-Senha-789!'))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('Nova-Senha-456!', $utilizador->fresh()->password));
    }

    public function test_token_invalido_nao_altera_a_palavra_passe(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->post('/redefinir-palavra-passe', $this->dadosDeRedefinicao($utilizador, 'token-inventado'))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('Senha-Antiga-123!', $utilizador->fresh()->password));
    }

    public function test_palavra_passe_fraca_ou_sem_confirmacao_e_recusada(): void
    {
        $utilizador = $this->criarUtilizador();
        $token = Password::createToken($utilizador);

        $this->post('/redefinir-palavra-passe', [
            'token' => $token,
            'email' => $utilizador->email,
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])->assertSessionHasErrors('password');

        $this->post('/redefinir-palavra-passe', [
            'token' => $token,
            'email' => $utilizador->email,
            'password' => 'Nova-Senha-456!',
            'password_confirmation' => 'diferente',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('Senha-Antiga-123!', $utilizador->fresh()->password));
    }

    public function test_conta_desativada_nao_consegue_redefinir(): void
    {
        $desativada = $this->criarUtilizador(ativo: false);
        $token = Password::createToken($desativada);

        $this->post('/redefinir-palavra-passe', $this->dadosDeRedefinicao($desativada, $token))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('Senha-Antiga-123!', $desativada->fresh()->password));
    }

    public function test_grupo_web_invalida_sessoes_quando_a_palavra_passe_muda(): void
    {
        $grupos = app(Kernel::class)->getMiddlewareGroups();

        $this->assertContains(AuthenticateSession::class, $grupos['web']);
    }
}
