<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\SubscricaoService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Passam pela camada HTTP a sério (middlewares, rotas, sessão).
 */
class IsolamentoPagamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->app->singleton(GatewayPagamentoInterface::class, fn () => new class implements GatewayPagamentoInterface
        {
            public function identificador(): string
            {
                return 'gateway-teste';
            }

            public function gerarReferencia(Pagamento $pagamento): array
            {
                return ['referencia_externa' => 'REF-'.$pagamento->id, 'payload' => ['ok' => true]];
            }

            public function validarPedidoWebhook(Request $request): bool
            {
                return true;
            }

            public function interpretarNotificacao(Request $request): array
            {
                return ['referencia_externa' => 'x', 'estado' => 'confirmado', 'payload' => []];
            }
        });
    }

    protected function criarEmpresa(string $nif): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif])->fresh();
    }

    protected function criarUtilizador(Empresa $empresa, string $papel = 'Administrador'): User
    {
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Teste',
            'email' => uniqid('u').'@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
        $utilizador->assignRole(Role::firstOrCreate([
            'name' => $papel,
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
        ]));

        return $utilizador;
    }

    protected function plano(string $slug): Plano
    {
        return Plano::create(['nome' => 'Plano '.$slug, 'slug' => $slug, 'preco' => 9900, 'periodo_dias' => 30]);
    }

    protected function cobrancaDe(Empresa $empresa, Plano $plano): Pagamento
    {
        return app(SubscricaoService::class)->iniciar($empresa, $plano);
    }

    public function test_administrador_ve_o_proprio_pagamento(): void
    {
        $empresa = $this->criarEmpresa('830000001');
        $admin = $this->criarUtilizador($empresa);
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-830000001'));

        $this->actingAs($admin)
            ->get(route('subscricoes.pendente', ['pagamento' => $pagamento->id]))
            ->assertOk();
    }

    public function test_administrador_nao_ve_pagamento_de_outra_empresa(): void
    {
        $adminA = $this->criarUtilizador($this->criarEmpresa('830000002'));
        $empresaB = $this->criarEmpresa('830000003');
        $pagamentoB = $this->cobrancaDe($empresaB, $this->plano('plano-830000003'));

        $this->actingAs($adminA)
            ->get(route('subscricoes.pendente', ['pagamento' => $pagamentoB->id]))
            ->assertNotFound();
    }

    public function test_utilizador_sem_papel_de_administrador_nao_inicia_subscricao(): void
    {
        $empresa = $this->criarEmpresa('830000004');
        $normal = $this->criarUtilizador($empresa, 'Utilizador');
        $plano = $this->plano('plano-830000004');

        $this->actingAs($normal)
            ->post(route('subscricoes.iniciar'), ['plano_id' => $plano->id])
            ->assertForbidden();

        $this->assertSame(0, Pagamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
    }

    public function test_utilizador_sem_papel_de_administrador_nao_cancela_renovacao(): void
    {
        $empresa = $this->criarEmpresa('830000005');
        $normal = $this->criarUtilizador($empresa, 'Utilizador');
        $this->cobrancaDe($empresa, $this->plano('plano-830000005'));
        $subscricao = Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();

        $this->actingAs($normal)
            ->post(route('subscricoes.renovacao', ['subscricao' => $subscricao->id]))
            ->assertForbidden();

        $this->assertTrue((bool) Subscricao::withoutGlobalScopes()->findOrFail($subscricao->id)->renovacao_automatica);
    }

    public function test_administrador_nao_altera_subscricao_de_outra_empresa(): void
    {
        $adminA = $this->criarUtilizador($this->criarEmpresa('830000006'));
        $empresaB = $this->criarEmpresa('830000007');
        $this->cobrancaDe($empresaB, $this->plano('plano-830000007'));
        $subscricaoB = Subscricao::withoutGlobalScopes()->where('empresa_id', $empresaB->id)->firstOrFail();

        $this->actingAs($adminA)
            ->post(route('subscricoes.renovacao', ['subscricao' => $subscricaoB->id]))
            ->assertNotFound();

        $this->assertTrue((bool) Subscricao::withoutGlobalScopes()->findOrFail($subscricaoB->id)->renovacao_automatica);
    }
}
