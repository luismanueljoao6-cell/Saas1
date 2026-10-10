<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Atelier\Models\PedidoOrcamento;
use Modules\Atelier\Models\Perfil;
use Modules\Core\Http\Middleware\ExigirServicoDaEmpresa;
use Modules\Core\Models\Empresa;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ServicosPublicosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function executarMiddleware(mixed $empresa, string $servico): Response
    {
        $request = Request::create('/qualquer');
        $request->setRouteResolver(fn () => new class($empresa)
        {
            public function __construct(protected mixed $empresa) {}

            public function parameter(string $nome): mixed
            {
                return $this->empresa;
            }
        });

        return app(ExigirServicoDaEmpresa::class)->handle(
            $request,
            fn () => new Response('ok'),
            $servico,
        );
    }

    protected function atelierPublicado(string $nif, string $slug, array $servicos): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$slug, 'nif' => $nif]);
        $empresa->forceFill(['slug' => $slug])->save();
        $empresa->aderirServicos($servicos);
        Perfil::create(['empresa_id' => $empresa->id, 'publicado' => true]);

        return $empresa;
    }

    public function test_middleware_deixa_passar_empresa_que_aderiu(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa A', 'nif' => '700000001']);
        $empresa->aderirServicos(['atelier']);

        $this->assertSame('ok', $this->executarMiddleware($empresa, 'atelier')->getContent());
    }

    public function test_middleware_da_404_se_a_empresa_nao_aderiu(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa B', 'nif' => '700000002']);
        $empresa->aderirServicos(['faturacao']);

        $this->expectException(NotFoundHttpException::class);

        $this->executarMiddleware($empresa, 'atelier');
    }

    public function test_middleware_da_404_se_o_parametro_nao_e_uma_empresa(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->executarMiddleware(null, 'atelier');
    }

    public function test_middleware_e_fail_closed_para_servico_desconhecido(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa C', 'nif' => '700000003']);
        $empresa->aderirServicos(['atelier', 'estudio']);

        $this->expectException(NotFoundHttpException::class);

        $this->executarMiddleware($empresa, 'inexistente');
    }

    public function test_landing_do_atelier_sem_o_servico_devolve_404(): void
    {
        $empresa = $this->atelierPublicado('700000004', 'atelier-sem-servico', ['faturacao']);

        $this->post(route('atelier.landing.solicitar-orcamento', ['empresa' => 'atelier-sem-servico']), [
            'nome' => 'Visitante',
            'contacto' => '900000000',
        ])->assertNotFound();

        $this->assertSame(0, PedidoOrcamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
    }

    public function test_remover_o_servico_fecha_a_landing_do_atelier(): void
    {
        $empresa = $this->atelierPublicado('700000005', 'atelier-aberto', ['atelier']);
        $dados = ['nome' => 'Visitante', 'contacto' => '900000000'];

        $this->post(route('atelier.landing.solicitar-orcamento', ['empresa' => 'atelier-aberto']), $dados)
            ->assertRedirect();

        $empresa->definirServicos(['faturacao']);

        $this->post(route('atelier.landing.solicitar-orcamento', ['empresa' => 'atelier-aberto']), $dados)
            ->assertNotFound();

        $this->assertSame(1, PedidoOrcamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
    }

    public function test_landing_do_estudio_sem_o_servico_devolve_404(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Estudio D', 'nif' => '700000006']);
        $empresa->aderirServicos(['faturacao']);

        $this->post(route('estudiomusica.landing.orcamento', ['empresa' => $empresa->id]), [
            'nome' => 'Visitante',
            'contacto' => '900000000',
        ])->assertNotFound();
    }
}
