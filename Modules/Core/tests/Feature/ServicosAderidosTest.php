<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Http\Middleware\ExigirServico;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Support\Servico;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ServicosAderidosTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Empresa, 1: User} */
    protected function empresaComUtilizador(string $nif, array $servicos, bool $superAdmin = false): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos($servicos);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador '.$nif,
            'email' => 'u'.$nif.'@teste.test',
            'password' => bcrypt('segredo123'),
            'is_super_admin' => $superAdmin,
        ]);

        return [$empresa, $utilizador];
    }

    protected function executarMiddleware(User $utilizador, string $servico): Response
    {
        Auth::login($utilizador);

        return app(ExigirServico::class)->handle(
            Request::create('/qualquer'),
            fn () => new Response('ok'),
            $servico,
        );
    }

    protected function dadosRegisto(array $extra = []): array
    {
        return array_merge([
            'nome_comercial' => 'Loja Nova',
            'nif' => '500000999',
            'name' => 'Dono da Loja',
            'email' => 'dono@loja.test',
            'password' => 'Zq7!vK2#mP9$wL4x',
            'password_confirmation' => 'Zq7!vK2#mP9$wL4x',
            'aceita_termos' => '1',
        ], $extra);
    }

    public function test_aderir_atelier_traz_a_faturacao_por_dependencia(): void
    {
        [$empresa] = $this->empresaComUtilizador('500000001', ['atelier']);

        $this->assertTrue($empresa->temServico(Servico::Atelier));
        $this->assertTrue($empresa->temServico('faturacao'));
        $this->assertFalse($empresa->temServico('estudio'));
    }

    public function test_aderir_so_faturacao_nao_da_acesso_aos_outros_servicos(): void
    {
        [$empresa] = $this->empresaComUtilizador('500000002', ['faturacao']);

        $this->assertTrue($empresa->temServico('faturacao'));
        $this->assertFalse($empresa->temServico('atelier'));
        $this->assertFalse($empresa->temServico('estudio'));
    }

    public function test_aderir_duas_vezes_nao_duplica(): void
    {
        [$empresa] = $this->empresaComUtilizador('500000003', ['faturacao']);
        $empresa->aderirServicos(['faturacao']);

        $this->assertSame(1, $empresa->servicos()->count());
    }

    public function test_middleware_deixa_passar_servico_aderido(): void
    {
        [, $utilizador] = $this->empresaComUtilizador('500000004', ['atelier']);

        $resposta = $this->executarMiddleware($utilizador, 'atelier');

        $this->assertSame('ok', $resposta->getContent());
    }

    public function test_middleware_bloqueia_servico_nao_aderido(): void
    {
        [, $utilizador] = $this->empresaComUtilizador('500000005', ['faturacao']);

        $resposta = $this->executarMiddleware($utilizador, 'atelier');

        $this->assertTrue($resposta->isRedirect(route('core.painel')));
    }

    public function test_middleware_e_fail_closed_para_servico_desconhecido(): void
    {
        [, $utilizador] = $this->empresaComUtilizador('500000006', Servico::valores());

        $resposta = $this->executarMiddleware($utilizador, 'inexistente');

        $this->assertTrue($resposta->isRedirect(route('core.painel')));
    }

    public function test_super_admin_nao_e_afetado(): void
    {
        [, $utilizador] = $this->empresaComUtilizador('500000007', ['faturacao'], superAdmin: true);

        $resposta = $this->executarMiddleware($utilizador, 'atelier');

        $this->assertSame('ok', $resposta->getContent());
    }

    public function test_rotas_de_servico_nao_aderido_redirecionam_para_o_painel(): void
    {
        [, $utilizador] = $this->empresaComUtilizador('500000008', ['faturacao']);

        $this->actingAs($utilizador)
            ->get(route('atelier.pedidos.index'))
            ->assertRedirect(route('core.painel'))
            ->assertSessionHas('erro');

        $this->actingAs($utilizador)
            ->get(route('estudiomusica.salas.index'))
            ->assertRedirect(route('core.painel'));
    }

    public function test_painel_so_mostra_os_servicos_aderidos(): void
    {
        [, $utilizador] = $this->empresaComUtilizador('500000009', ['faturacao']);

        $this->actingAs($utilizador)
            ->get(route('core.painel'))
            ->assertOk()
            ->assertSee('Faturação')
            ->assertDontSee('Atelier de Costura')
            ->assertDontSee('Pedidos (Atelier)')
            ->assertDontSee('Estúdio de Música');
    }

    public function test_painel_com_atelier_mostra_tambem_a_faturacao(): void
    {
        [, $utilizador] = $this->empresaComUtilizador('500000010', ['atelier']);

        $this->actingAs($utilizador)
            ->get(route('core.painel'))
            ->assertOk()
            ->assertSee('Atelier de Costura')
            ->assertSee('Faturação')
            ->assertDontSee('Estúdio de Música');
    }

    public function test_registo_guarda_os_servicos_escolhidos(): void
    {
        Notification::fake();

        $this->post(route('core.registo'), $this->dadosRegisto(['servicos' => ['faturacao']]))
            ->assertSessionHasNoErrors();

        $empresa = Empresa::where('nif', '500000999')->firstOrFail();

        $this->assertSame(['faturacao'], $empresa->servicos()->pluck('servico')->all());
    }

    public function test_registo_com_estudio_inclui_a_faturacao(): void
    {
        Notification::fake();

        $this->post(route('core.registo'), $this->dadosRegisto(['servicos' => ['estudio']]))
            ->assertSessionHasNoErrors();

        $empresa = Empresa::where('nif', '500000999')->firstOrFail();

        $this->assertSame(
            ['estudio', 'faturacao'],
            $empresa->servicos()->pluck('servico')->sort()->values()->all()
        );
    }

    public function test_registo_exige_pelo_menos_um_servico(): void
    {
        $this->post(route('core.registo'), $this->dadosRegisto())
            ->assertSessionHasErrors('servicos');

        $this->assertSame(0, Empresa::where('nif', '500000999')->count());
    }

    public function test_registo_rejeita_servico_inexistente(): void
    {
        $this->post(route('core.registo'), $this->dadosRegisto(['servicos' => ['banana']]))
            ->assertSessionHasErrors('servicos.0');

        $this->assertSame(0, Empresa::where('nif', '500000999')->count());
    }
}
