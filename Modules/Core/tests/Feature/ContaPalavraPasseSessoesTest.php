<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * Alterar a palavra-passe e terminar as outras sessões, a partir das
 * definições de segurança da conta.
 */
class ContaPalavraPasseSessoesTest extends TestCase
{
    use RefreshDatabase;

    protected const SENHA = 'Senha-Forte-123!';

    protected const NOVA = 'Nova-Senha-Forte-456!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function criarUtilizador(string $nif = '580000001'): User
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);

        return User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Conta',
            'email' => 'u'.$nif.'@teste.ao',
            'password' => self::SENHA,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function dadosDeAlteracao(string $atual = self::SENHA, string $nova = self::NOVA, ?string $confirmacao = null): array
    {
        return [
            'password_atual' => $atual,
            'password' => $nova,
            'password_confirmation' => $confirmacao ?? $nova,
        ];
    }

    public function test_visitante_e_enviado_para_o_login(): void
    {
        $this->put(route('core.seguranca.palavra-passe'), [])->assertRedirect(route('core.login'));
        $this->post(route('core.seguranca.sessoes.terminar'), [])->assertRedirect(route('core.login'));
    }

    public function test_a_pagina_mostra_as_duas_seccoes(): void
    {
        $this->actingAs($this->criarUtilizador())
            ->get(route('core.seguranca.index'))
            ->assertOk()
            ->assertSee('Alterar palavra-passe')
            ->assertSee('Terminar outras sessões');
    }

    public function test_alterar_com_dados_certos_troca_a_palavra_passe_e_revoga_acessos(): void
    {
        $utilizador = $this->criarUtilizador();
        $utilizador->forceFill(['remember_token' => 'token-antigo'])->save();
        $utilizador->createToken('integracao');
        $this->assertSame(1, $utilizador->tokens()->count());

        $this->actingAs($utilizador)
            ->put(route('core.seguranca.palavra-passe'), $this->dadosDeAlteracao())
            ->assertRedirect(route('core.seguranca.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sucesso');

        $utilizador->refresh();

        $this->assertTrue(Hash::check(self::NOVA, $utilizador->password));
        $this->assertNotSame('token-antigo', $utilizador->remember_token);
        $this->assertSame(0, $utilizador->tokens()->count());
    }

    public function test_a_sessao_atual_continua_valida_depois_de_alterar(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->actingAs($utilizador)
            ->put(route('core.seguranca.palavra-passe'), $this->dadosDeAlteracao())
            ->assertSessionHasNoErrors();

        $this->actingAs($utilizador->fresh())
            ->get(route('core.seguranca.index'))
            ->assertOk();
    }

    public function test_uma_sessao_que_ficou_com_o_hash_antigo_e_terminada(): void
    {
        $utilizador = $this->criarUtilizador();
        $hashAntigo = $utilizador->password;

        $this->actingAs($utilizador)
            ->put(route('core.seguranca.palavra-passe'), $this->dadosDeAlteracao())
            ->assertSessionHasNoErrors();

        // Outro navegador: a sessão dele guardou o hash da palavra-passe antiga.
        $this->flushSession();

        $this->withSession(['password_hash_web' => $hashAntigo])
            ->actingAs($utilizador->fresh())
            ->get(route('core.seguranca.index'))
            ->assertRedirect(route('core.login'));
    }

    public function test_palavra_passe_atual_errada_nao_altera_nada(): void
    {
        $utilizador = $this->criarUtilizador();
        $utilizador->createToken('integracao');

        $this->actingAs($utilizador)
            ->put(route('core.seguranca.palavra-passe'), $this->dadosDeAlteracao(atual: 'errada'))
            ->assertSessionHasErrors('password_atual');

        $utilizador->refresh();

        $this->assertTrue(Hash::check(self::SENHA, $utilizador->password));
        $this->assertSame(1, $utilizador->tokens()->count());
    }

    public function test_nova_igual_a_atual_e_recusada(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->actingAs($utilizador)
            ->put(route('core.seguranca.palavra-passe'), $this->dadosDeAlteracao(nova: self::SENHA))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::SENHA, $utilizador->fresh()->password));
    }

    public function test_nova_fraca_ou_sem_confirmacao_e_recusada(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->actingAs($utilizador)
            ->put(route('core.seguranca.palavra-passe'), $this->dadosDeAlteracao(nova: 'abc'))
            ->assertSessionHasErrors('password');

        $this->actingAs($utilizador)
            ->put(route('core.seguranca.palavra-passe'), $this->dadosDeAlteracao(confirmacao: 'diferente'))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::SENHA, $utilizador->fresh()->password));
    }

    public function test_terminar_outras_sessoes_renova_o_hash_e_revoga_tokens(): void
    {
        $utilizador = $this->criarUtilizador();
        $utilizador->createToken('integracao');
        $hashAntes = $utilizador->password;

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.sessoes.terminar'), ['password' => self::SENHA])
            ->assertRedirect(route('core.seguranca.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sucesso');

        $utilizador->refresh();

        // Mesmo valor, hash novo: é isso que invalida as outras sessões.
        $this->assertNotSame($hashAntes, $utilizador->password);
        $this->assertTrue(Hash::check(self::SENHA, $utilizador->password));
        $this->assertSame(0, $utilizador->tokens()->count());
    }

    public function test_terminar_outras_sessoes_exige_a_palavra_passe_certa(): void
    {
        $utilizador = $this->criarUtilizador();
        $utilizador->createToken('integracao');
        $hashAntes = $utilizador->password;

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.sessoes.terminar'), ['password' => 'errada'])
            ->assertSessionHasErrors('password');

        $utilizador->refresh();

        $this->assertSame($hashAntes, $utilizador->password);
        $this->assertSame(1, $utilizador->tokens()->count());
    }
}
