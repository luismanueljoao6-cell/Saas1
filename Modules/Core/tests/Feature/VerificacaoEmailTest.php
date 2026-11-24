<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Notifications\VerificarEmail;
use Modules\Core\Services\RegistoEmpresaService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VerificacaoEmailTest extends TestCase
{
    use RefreshDatabase;

    private static int $contador = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function criarAdministrador(bool $verificado): User
    {
        $n = ++self::$contador;

        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa Verificação '.$n,
            'nif' => '53'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        ]);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Administrador '.$n,
            'email' => "verificacao{$n}@example.test",
            'password' => 'Senha-Forte-123!',
        ]);

        if ($verificado) {
            $utilizador->markEmailAsVerified();
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($empresa->id);
        $utilizador->assignRole(Role::firstOrCreate([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
        ]));
        $registrar->setPermissionsTeamId(null);

        return $utilizador;
    }

    protected function urlDeVerificacao(User $utilizador, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $utilizador->getKey(),
            'hash' => $hash ?? sha1($utilizador->getEmailForVerification()),
        ]);
    }

    public function test_pagina_de_aviso_aparece_a_quem_nao_verificou(): void
    {
        $utilizador = $this->criarAdministrador(verificado: false);

        $this->actingAs($utilizador)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Verifica o teu e-mail');
    }

    public function test_quem_ja_verificou_e_reencaminhado_para_o_painel(): void
    {
        $utilizador = $this->criarAdministrador(verificado: true);

        $this->actingAs($utilizador)
            ->get(route('verification.notice'))
            ->assertRedirect(route('core.painel'));
    }

    public function test_ligacao_assinada_verifica_o_email(): void
    {
        $utilizador = $this->criarAdministrador(verificado: false);

        $this->actingAs($utilizador)
            ->get($this->urlDeVerificacao($utilizador))
            ->assertRedirect(route('core.painel'));

        $this->assertNotNull($utilizador->fresh()->email_verified_at);
    }

    public function test_hash_errado_e_recusado(): void
    {
        $utilizador = $this->criarAdministrador(verificado: false);

        $this->actingAs($utilizador)
            ->get($this->urlDeVerificacao($utilizador, 'hash-errado'))
            ->assertForbidden();

        $this->assertNull($utilizador->fresh()->email_verified_at);
    }

    public function test_ligacao_de_outro_utilizador_e_recusada(): void
    {
        $a = $this->criarAdministrador(verificado: false);
        $b = $this->criarAdministrador(verificado: false);

        $this->actingAs($a)
            ->get($this->urlDeVerificacao($b))
            ->assertForbidden();

        $this->assertNull($b->fresh()->email_verified_at);
    }

    public function test_ligacao_sem_assinatura_e_recusada(): void
    {
        $utilizador = $this->criarAdministrador(verificado: false);

        $semAssinatura = route('verification.verify', [
            'id' => $utilizador->getKey(),
            'hash' => sha1($utilizador->getEmailForVerification()),
        ]);

        $this->actingAs($utilizador)->get($semAssinatura)->assertForbidden();

        $this->assertNull($utilizador->fresh()->email_verified_at);
    }

    public function test_reenvio_envia_a_notificacao(): void
    {
        Notification::fake();
        $utilizador = $this->criarAdministrador(verificado: false);

        $this->actingAs($utilizador)
            ->post(route('verification.send'))
            ->assertSessionHas('status');

        Notification::assertSentTo($utilizador, VerificarEmail::class);
    }

    public function test_reenvio_nao_envia_a_quem_ja_verificou(): void
    {
        Notification::fake();
        $utilizador = $this->criarAdministrador(verificado: true);

        $this->actingAs($utilizador)
            ->post(route('verification.send'))
            ->assertRedirect(route('core.painel'));

        Notification::assertNothingSent();
    }

    public function test_registo_envia_o_email_de_verificacao(): void
    {
        Notification::fake();

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

        $this->post('/registo', [
            'nome_comercial' => 'Empresa de Verificação',
            'nif' => '540000001',
            'name' => 'Administrador',
            'email' => 'registo-verificacao@example.test',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
            'aceita_termos' => '1',
            'servicos' => ['faturacao'],
        ]);

        $utilizador = User::query()->where('email', 'registo-verificacao@example.test')->firstOrFail();

        Notification::assertSentTo($utilizador, VerificarEmail::class);
    }

    public function test_por_omissao_a_verificacao_nao_e_exigida(): void
    {
        $utilizador = $this->criarAdministrador(verificado: false);

        $this->actingAs($utilizador)->get('/utilizadores')->assertOk();
    }

    public function test_com_exigencia_ativa_o_nao_verificado_vai_para_o_aviso(): void
    {
        config(['core.auth.exigir_email_verificado' => true]);
        $utilizador = $this->criarAdministrador(verificado: false);

        $this->actingAs($utilizador)
            ->get('/utilizadores')
            ->assertRedirect(route('verification.notice'));

        // A página de aviso tem de continuar acessível (sem ciclo de redirecionamentos).
        $this->actingAs($utilizador)->get(route('verification.notice'))->assertOk();
    }

    public function test_com_exigencia_ativa_o_verificado_acede(): void
    {
        config(['core.auth.exigir_email_verificado' => true]);
        $utilizador = $this->criarAdministrador(verificado: true);

        $this->actingAs($utilizador)->get('/utilizadores')->assertOk();
    }

    public function test_com_exigencia_ativa_pedidos_json_recebem_403(): void
    {
        config(['core.auth.exigir_email_verificado' => true]);
        $utilizador = $this->criarAdministrador(verificado: false);

        $this->actingAs($utilizador)->getJson('/utilizadores')->assertForbidden();
    }
}
