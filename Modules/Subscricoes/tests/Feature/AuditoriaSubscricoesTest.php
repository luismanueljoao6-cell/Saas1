<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Subscricoes\Exceptions\PedidoEmPreparacaoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Models\WebhookEvento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\PagamentoService;
use Modules\Subscricoes\Services\SubscricaoService;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regressões da auditoria de 26/10/2026 ao módulo Subscrições.
 * Correr só estes: php artisan test --group=auditoria
 */
#[Group('auditoria')]
class AuditoriaSubscricoesTest extends TestCase
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
            'ativo' => true,
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

    public function test_cancelar_renovacao_de_subscricao_pendente_e_recusado_e_preserva_o_pagamento(): void
    {
        $empresa = $this->criarEmpresa('840000001');
        $admin = $this->criarUtilizador($empresa);
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000001'));
        $subscricao = Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();

        $this->actingAs($admin)
            ->from(route('subscricoes.planos'))
            ->post(route('subscricoes.renovacao', ['subscricao' => $subscricao->id]))
            ->assertRedirect(route('subscricoes.planos'))
            ->assertSessionHas('erro');

        $this->assertSame('pendente', Pagamento::withoutGlobalScopes()->findOrFail($pagamento->id)->estado);
        $this->assertTrue((bool) Subscricao::withoutGlobalScopes()->findOrFail($subscricao->id)->renovacao_automatica);
    }

    public function test_retomar_renovacao_de_subscricao_vencida_mostra_erro_em_vez_de_falhar(): void
    {
        $empresa = $this->criarEmpresa('840000002');
        $admin = $this->criarUtilizador($empresa);
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000002'));
        app(PagamentoService::class)->confirmar($pagamento, [], null, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();
        $subscricao->update([
            'renovacao_automatica' => false,
            'cancelada_em' => now(),
            'termina_em' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->from(route('subscricoes.planos'))
            ->post(route('subscricoes.renovacao', ['subscricao' => $subscricao->id]))
            ->assertRedirect(route('subscricoes.planos'))
            ->assertSessionHas('erro');

        $this->assertFalse((bool) Subscricao::withoutGlobalScopes()->findOrFail($subscricao->id)->renovacao_automatica);
    }

    public function test_iniciar_para_outro_plano_com_pagamento_pendente_mostra_a_mensagem_de_negocio(): void
    {
        $empresa = $this->criarEmpresa('840000003');
        $admin = $this->criarUtilizador($empresa);
        $this->cobrancaDe($empresa, $this->plano('plano-840000003a'));
        $outro = $this->plano('plano-840000003b');

        $this->actingAs($admin)
            ->from(route('subscricoes.planos'))
            ->post(route('subscricoes.iniciar'), ['plano_id' => $outro->id])
            ->assertRedirect(route('subscricoes.planos'))
            ->assertSessionHas('erro', fn ($mensagem) => str_contains((string) $mensagem, 'outro plano'));
    }

    public function test_pedido_recente_sem_referencia_continua_a_bloquear_com_excecao_especifica(): void
    {
        $empresa = $this->criarEmpresa('840000004');
        $plano = $this->plano('plano-840000004');
        $pagamento = $this->cobrancaDe($empresa, $plano);
        Pagamento::withoutGlobalScopes()->whereKey($pagamento->id)->update(['referencia_externa' => null]);

        $this->expectException(PedidoEmPreparacaoException::class);

        app(SubscricaoService::class)->iniciar($empresa, $plano);
    }

    public function test_pedido_abandonado_sem_referencia_e_descartado_e_a_empresa_pode_tentar_de_novo(): void
    {
        $empresa = $this->criarEmpresa('840000005');
        $plano = $this->plano('plano-840000005');
        $abandonado = $this->cobrancaDe($empresa, $plano);
        Pagamento::withoutGlobalScopes()->whereKey($abandonado->id)->update([
            'referencia_externa' => null,
            'created_at' => now()->subMinutes(10),
        ]);

        $novo = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->assertNotSame($abandonado->id, $novo->id);
        $this->assertNotEmpty($novo->referencia_externa);
        $this->assertSame('expirado', Pagamento::withoutGlobalScopes()->findOrFail($abandonado->id)->estado);
        $this->assertSame('cancelada', Subscricao::withoutGlobalScopes()->findOrFail($abandonado->subscricao_id)->estado);
    }

    public function test_servico_desconhecido_no_plano_nao_impede_a_confirmacao_do_pagamento(): void
    {
        $empresa = $this->criarEmpresa('840000006');
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000006'));

        $subscricao = Subscricao::withoutGlobalScopes()->findOrFail($pagamento->subscricao_id);
        $subscricao->servicos = ['servico-que-nao-existe'];
        $subscricao->save();

        $confirmado = app(PagamentoService::class)->confirmar($pagamento->fresh(), [], null, '9900.00');

        $this->assertSame('confirmado', $confirmado->estado);
        $this->assertSame('ativa', $empresa->fresh()->estado_subscricao);
    }

    public function test_confirmacao_manual_de_subscricao_cancelada_limpa_cancelada_em(): void
    {
        $empresa = $this->criarEmpresa('840000007');
        $operador = $this->criarUtilizador($empresa);
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000007'));

        // Estado deixado pela rotina diária: pagamento expirado e subscrição cancelada.
        Pagamento::withoutGlobalScopes()->whereKey($pagamento->id)->update(['estado' => 'expirado']);
        Subscricao::withoutGlobalScopes()->whereKey($pagamento->subscricao_id)->update([
            'estado' => 'cancelada',
            'cancelada_em' => now(),
        ]);

        app(PagamentoService::class)->confirmar($pagamento->fresh(), [], $operador, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()->findOrFail($pagamento->subscricao_id);
        $this->assertSame('ativa', $subscricao->estado);
        $this->assertNull($subscricao->cancelada_em);
    }

    public function test_reconciliacao_conta_uma_so_tentativa_por_reprocessamento(): void
    {
        $empresa = $this->criarEmpresa('840000008');
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000008'));

        $evento = WebhookEvento::query()->create([
            'gateway' => 'gateway-teste',
            'chave_evento' => hash('sha256', 'auditoria-840000008'),
            'referencia_externa' => $pagamento->referencia_externa,
            'valor' => '9900.00',
            'corpo_bruto' => '{"reference_id":"'.$pagamento->referencia_externa.'","amount":"9900.00"}',
            'estado' => WebhookEvento::RECEBIDO,
        ]);
        WebhookEvento::query()->whereKey($evento->id)->update(['updated_at' => now()->subHour()]);

        Artisan::call('subscricoes:reconciliar');

        $evento = $evento->fresh();
        $this->assertSame(WebhookEvento::PROCESSADO, $evento->estado);
        $this->assertSame(1, (int) $evento->tentativas);
    }

    public function test_reconciliacao_descarta_pagamento_sem_referencia_abandonado(): void
    {
        $empresa = $this->criarEmpresa('840000009');
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000009'));
        Pagamento::withoutGlobalScopes()->whereKey($pagamento->id)->update([
            'referencia_externa' => null,
            'created_at' => now()->subHour(),
        ]);

        $codigo = Artisan::call('subscricoes:reconciliar');

        $this->assertSame(0, $codigo);
        $this->assertSame('expirado', Pagamento::withoutGlobalScopes()->findOrFail($pagamento->id)->estado);
        $this->assertSame('cancelada', Subscricao::withoutGlobalScopes()->findOrFail($pagamento->subscricao_id)->estado);
    }

    public function test_comando_de_preco_rejeita_valores_que_o_gateway_nao_aceita(): void
    {
        $plano = $this->plano('plano-840000010');

        foreach (['0.001', '1e3', '12.345', '100000000', '0'] as $preco) {
            $this->artisan('planos:preco', ['slug' => $plano->slug, 'preco' => $preco])->assertExitCode(1);
        }

        $this->assertEquals(9900, (float) Plano::findOrFail($plano->id)->preco);
    }
}
