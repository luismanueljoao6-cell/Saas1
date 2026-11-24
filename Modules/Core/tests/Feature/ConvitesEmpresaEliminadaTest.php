<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Notifications\ConviteDeUtilizador;
use Modules\Core\Services\CicloDeVidaEmpresaService;
use Modules\Core\Services\ConviteService;
use Tests\TestCase;

/**
 * Um convite pendente de uma empresa eliminada logicamente não pode ser usado
 * nem rebentar: mesma resposta que qualquer convite inválido. Ao restaurar a
 * empresa, o convite volta a valer.
 */
class ConvitesEmpresaEliminadaTest extends TestCase
{
    use RefreshDatabase;

    protected const SENHA = 'Senha-Forte-123!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    /**
     * @return array{0: Empresa, 1: string} a empresa e o token em claro do convite
     */
    protected function emitirConvite(): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Eliminavel', 'nif' => '590000001']);

        $admin = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Admin Teste',
            'email' => 'admin-eliminavel@teste.ao',
            'password' => self::SENHA,
        ]);

        app(ConviteService::class)->convidar($admin, 'convidado@teste.ao', 'Convidado', false);

        $token = null;

        Notification::assertSentOnDemand(ConviteDeUtilizador::class, function (ConviteDeUtilizador $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return [$empresa, $token];
    }

    public function test_a_pagina_do_convite_de_empresa_eliminada_responde_404_sem_erro(): void
    {
        [$empresa, $token] = $this->emitirConvite();

        app(CicloDeVidaEmpresaService::class)->eliminar($empresa->id);

        $this->get(route('core.convite.mostrar', $token))
            ->assertNotFound()
            ->assertSee(ConviteService::MENSAGEM_INVALIDO)
            ->assertDontSee('Atelier Eliminavel');
    }

    public function test_aceitar_um_convite_de_empresa_eliminada_e_recusado_sem_criar_utilizador(): void
    {
        [$empresa, $token] = $this->emitirConvite();

        app(CicloDeVidaEmpresaService::class)->eliminar($empresa->id);

        $this->post(route('core.convite.aceitar', $token), [
            'name' => 'Convidado',
            'password' => self::SENHA,
            'password_confirmation' => self::SENHA,
        ])->assertSessionHas('erro', ConviteService::MENSAGEM_INVALIDO);

        $this->assertDatabaseMissing('users', ['email' => 'convidado@teste.ao']);
        $this->assertGuest();
    }

    public function test_restaurar_a_empresa_devolve_a_validade_ao_convite(): void
    {
        [$empresa, $token] = $this->emitirConvite();

        $servico = app(CicloDeVidaEmpresaService::class);
        $servico->eliminar($empresa->id);
        $servico->restaurar($empresa->id);

        $this->get(route('core.convite.mostrar', $token))
            ->assertOk()
            ->assertSee('Atelier Eliminavel')
            ->assertSee('convidado@teste.ao');
    }
}
