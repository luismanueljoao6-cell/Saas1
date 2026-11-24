<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Models\Convite;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Notifications\ConviteDeUtilizador;
use Modules\Core\Services\ConviteService;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConvitesHttpTest extends TestCase
{
    use RefreshDatabase;

    protected const SENHA = 'Senha-Forte-123!';

    protected Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    /** Emite um convite pelo serviço e devolve o token em claro (o do e-mail). */
    protected function emitirConvite(string $email = 'novo@teste.ao'): string
    {
        $this->empresa = Empresa::create(['nome_comercial' => 'Atelier Luanda', 'nif' => '520000001']);

        $admin = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Admin Teste',
            'email' => 'admin@teste.ao',
            'password' => self::SENHA,
        ]);

        app(ConviteService::class)->convidar($admin, $email, 'Novo Colega', false);

        $token = null;

        Notification::assertSentOnDemand(ConviteDeUtilizador::class, function (ConviteDeUtilizador $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    protected function dadosAceitacao(array $extra = []): array
    {
        return array_merge([
            'name' => 'Novo Colega',
            'password' => self::SENHA,
            'password_confirmation' => self::SENHA,
        ], $extra);
    }

    public function test_a_pagina_mostra_a_empresa_e_o_email_do_convite(): void
    {
        $token = $this->emitirConvite();

        $this->get(route('core.convite.mostrar', $token))
            ->assertOk()
            ->assertSee('Atelier Luanda')
            ->assertSee('novo@teste.ao');
    }

    public function test_a_pagina_do_convite_nao_envia_o_token_em_referer(): void
    {
        $token = $this->emitirConvite();

        $this->get(route('core.convite.mostrar', $token))
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_token_desconhecido_da_404_sem_revelar_nada(): void
    {
        $this->emitirConvite();

        $this->get(route('core.convite.mostrar', str_repeat('a', 64)))
            ->assertNotFound()
            ->assertDontSee('Atelier Luanda')
            ->assertDontSee('novo@teste.ao');
    }

    public function test_aceitar_cria_a_conta_e_autentica(): void
    {
        $token = $this->emitirConvite();

        $this->post(route('core.convite.aceitar', $token), $this->dadosAceitacao())
            ->assertRedirect(route('core.painel'));

        $utilizador = User::where('email', 'novo@teste.ao')->firstOrFail();

        $this->assertAuthenticatedAs($utilizador);
        $this->assertSame((int) $this->empresa->id, (int) $utilizador->empresa_id);
        $this->assertTrue($utilizador->hasVerifiedEmail());
    }

    public function test_e_mail_empresa_e_papel_vem_sempre_do_convite(): void
    {
        $token = $this->emitirConvite();

        $this->post(route('core.convite.aceitar', $token), $this->dadosAceitacao([
            'email' => 'outro@teste.ao',
            'empresa_id' => 999,
            'administrador' => 1,
        ]));

        $this->assertDatabaseMissing('users', ['email' => 'outro@teste.ao']);

        $utilizador = User::where('email', 'novo@teste.ao')->firstOrFail();
        $this->assertSame((int) $this->empresa->id, (int) $utilizador->empresa_id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->empresa->id);
        $this->assertFalse($utilizador->fresh()->hasRole('Administrador'));
        $this->assertTrue($utilizador->fresh()->hasRole('Utilizador'));
    }

    public function test_palavra_passe_fraca_e_recusada_e_o_convite_continua_valido(): void
    {
        $token = $this->emitirConvite();

        $this->post(route('core.convite.aceitar', $token), $this->dadosAceitacao([
            'password' => 'curta',
            'password_confirmation' => 'curta',
        ]))->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'novo@teste.ao']);
        $this->get(route('core.convite.mostrar', $token))->assertOk();
    }

    public function test_convite_expirado_nao_abre_nem_aceita(): void
    {
        $token = $this->emitirConvite();
        Convite::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);

        $this->get(route('core.convite.mostrar', $token))->assertNotFound();

        $this->post(route('core.convite.aceitar', $token), $this->dadosAceitacao())
            ->assertSessionHas('erro');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'novo@teste.ao']);
    }

    public function test_um_convite_aceite_deixa_de_abrir(): void
    {
        $token = $this->emitirConvite();

        $this->post(route('core.convite.aceitar', $token), $this->dadosAceitacao())
            ->assertRedirect(route('core.painel'));

        $this->app['auth']->guard('web')->logout();

        $this->get(route('core.convite.mostrar', $token))->assertNotFound();
    }
}
