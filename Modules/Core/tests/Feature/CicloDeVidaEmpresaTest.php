<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\CicloDeVidaEmpresaService;
use Tests\TestCase;

/**
 * Eliminação lógica e restauro da empresa: idempotentes, sem apagar dados, e com
 * o acesso a fechar (e a reabrir) em todas as rotas com 'tenant' e no login.
 */
class CicloDeVidaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'Senha-Forte-123!';

    private const MENSAGEM_EMPRESA = 'A empresa desta conta já não está disponível. Contacta o suporte.';

    private static int $nif = 620000000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function empresa(): Empresa
    {
        $nif = (string) self::$nif++;

        return Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
    }

    protected function utilizador(Empresa $empresa): User
    {
        return User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador '.$empresa->nif,
            'email' => 'u'.$empresa->nif.'@teste.test',
            'password' => self::SENHA,
        ]);
    }

    protected function servico(): CicloDeVidaEmpresaService
    {
        return app(CicloDeVidaEmpresaService::class);
    }

    public function test_eliminar_e_idempotente_e_nao_apaga_dados(): void
    {
        $empresa = $this->empresa();
        $this->utilizador($empresa);

        $this->assertTrue($this->servico()->eliminar($empresa->id));
        $this->assertFalse($this->servico()->eliminar($empresa->id));

        $this->assertSoftDeleted('empresas', ['id' => $empresa->id]);
        $this->assertSame(1, DB::table('users')->where('empresa_id', $empresa->id)->count());
    }

    public function test_restaurar_e_idempotente(): void
    {
        $empresa = $this->empresa();
        $this->servico()->eliminar($empresa->id);

        $this->assertTrue($this->servico()->restaurar($empresa->id));
        $this->assertFalse($this->servico()->restaurar($empresa->id));

        $this->assertNotSoftDeleted('empresas', ['id' => $empresa->id]);
    }

    public function test_empresa_inexistente_e_recusada(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->servico()->eliminar(999999);
    }

    public function test_sessao_aberta_e_terminada_numa_rota_so_com_tenant(): void
    {
        $empresa = $this->empresa();
        $utilizador = $this->utilizador($empresa);

        $this->actingAs($utilizador);
        $this->servico()->eliminar($empresa->id);

        // A rota de segurança só tem 'auth'+'tenant' (sem 'subscricao.ativa'): é a que
        // antes deixava passar os utilizadores de uma empresa eliminada.
        $this->get(route('core.seguranca.index'))
            ->assertRedirect(route('core.login'))
            ->assertSessionHasErrors(['email' => self::MENSAGEM_EMPRESA]);

        $this->assertGuest();
    }

    public function test_pedido_de_api_de_empresa_eliminada_recebe_403(): void
    {
        $empresa = $this->empresa();
        $utilizador = $this->utilizador($empresa);

        $this->actingAs($utilizador);
        $this->servico()->eliminar($empresa->id);

        $this->getJson(route('core.seguranca.index'))->assertForbidden();
    }

    public function test_eliminar_uma_empresa_nao_afeta_as_outras(): void
    {
        $a = $this->empresa();
        $b = $this->empresa();
        $this->utilizador($a);
        $utilizadorB = $this->utilizador($b);

        $this->servico()->eliminar($a->id);

        $this->actingAs($utilizadorB)
            ->get(route('core.seguranca.index'))
            ->assertOk();
    }

    public function test_login_com_palavra_passe_certa_de_empresa_eliminada_e_recusado(): void
    {
        $empresa = $this->empresa();
        $utilizador = $this->utilizador($empresa);
        $this->servico()->eliminar($empresa->id);

        $this->post(route('core.login'), ['email' => $utilizador->email, 'password' => self::SENHA])
            ->assertSessionHasErrors(['email' => self::MENSAGEM_EMPRESA]);

        $this->assertGuest();
    }

    public function test_login_com_palavra_passe_errada_nao_revela_que_a_empresa_foi_eliminada(): void
    {
        $empresa = $this->empresa();
        $utilizador = $this->utilizador($empresa);
        $this->servico()->eliminar($empresa->id);

        $this->post(route('core.login'), ['email' => $utilizador->email, 'password' => 'errada'])
            ->assertSessionHasErrors(['email' => 'As credenciais indicadas não são válidas.']);

        $this->assertGuest();
    }

    public function test_depois_de_restaurar_o_login_volta_a_funcionar(): void
    {
        $empresa = $this->empresa();
        $utilizador = $this->utilizador($empresa);
        $this->servico()->eliminar($empresa->id);
        $this->servico()->restaurar($empresa->id);

        $this->post(route('core.login'), ['email' => $utilizador->email, 'password' => self::SENHA])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($utilizador);
    }

    public function test_comando_eliminar_exige_confirmacao(): void
    {
        $empresa = $this->empresa();

        $this->artisan('core:empresa-eliminar', ['empresa' => $empresa->id])
            ->expectsOutputToContain('--confirmar')
            ->assertExitCode(1);

        $this->assertNotSoftDeleted('empresas', ['id' => $empresa->id]);
    }

    public function test_comandos_eliminar_e_restaurar_sao_idempotentes(): void
    {
        $empresa = $this->empresa();
        $argumentos = ['empresa' => $empresa->id, '--confirmar' => true];

        $this->artisan('core:empresa-eliminar', $argumentos)
            ->expectsOutputToContain('eliminada logicamente')
            ->assertExitCode(0);
        $this->artisan('core:empresa-eliminar', $argumentos)
            ->expectsOutputToContain('já estava eliminada')
            ->assertExitCode(0);
        $this->assertSoftDeleted('empresas', ['id' => $empresa->id]);

        $this->artisan('core:empresa-restaurar', ['empresa' => $empresa->id])
            ->expectsOutputToContain('restaurada')
            ->assertExitCode(0);
        $this->artisan('core:empresa-restaurar', ['empresa' => $empresa->id])
            ->expectsOutputToContain('já estava ativa')
            ->assertExitCode(0);
        $this->assertNotSoftDeleted('empresas', ['id' => $empresa->id]);
    }

    public function test_comandos_recusam_empresa_inexistente_e_id_invalido(): void
    {
        $this->artisan('core:empresa-eliminar', ['empresa' => 999999, '--confirmar' => true])
            ->expectsOutputToContain('não encontrada')
            ->assertExitCode(1);

        $this->artisan('core:empresa-restaurar', ['empresa' => 'abc'])
            ->expectsOutputToContain('inteiro positivo')
            ->assertExitCode(1);
    }
}
