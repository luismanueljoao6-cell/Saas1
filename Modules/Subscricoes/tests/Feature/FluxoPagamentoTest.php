<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Subscricoes\Exceptions\PagamentoRejeitadoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\PagamentoService;
use Modules\Subscricoes\Services\SubscricaoService;
use Tests\TestCase;

class FluxoPagamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Gateway falso: sem HTTP real, determinístico. É um singleton para
        // o contador de referências persistir entre serviços resolvidos no
        // mesmo teste (referências únicas: REF-1, REF-2, ...).
        $this->app->singleton(GatewayPagamentoInterface::class, fn () => new class implements GatewayPagamentoInterface
        {
            private int $contador = 0;

            public function identificador(): string
            {
                return 'gateway-teste';
            }

            public function gerarReferencia(Pagamento $pagamento): array
            {
                $this->contador++;

                return ['referencia_externa' => 'REF-'.$this->contador, 'payload' => ['ok' => true]];
            }

            public function validarPedidoWebhook(Request $request): bool
            {
                return true;
            }

            public function interpretarNotificacao(Request $request): array
            {
                return ['referencia_externa' => $request->input('reference'), 'estado' => 'confirmado', 'payload' => []];
            }
        });
    }

    protected function criarEmpresa(string $nif, string $estado = 'trial'): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa Teste '.$nif, 'nif' => $nif]);
        $empresa->update(['estado_subscricao' => $estado]);

        return $empresa->fresh();
    }

    protected function criarPlano(string $slug, int $periodoDias = 30): Plano
    {
        return Plano::create(['nome' => 'Plano '.$slug, 'slug' => $slug, 'preco' => 9900, 'periodo_dias' => $periodoDias]);
    }

    /**
     * Simula a notificação do gateway: o payload traz o valor pago, que a
     * regra de negócio exige (SUBSCRICOES_EXIGIR_VALOR_WEBHOOK=true).
     */
    protected function confirmarComoGateway(Pagamento $pagamento, ?float $valorPago = null): Pagamento
    {
        return app(PagamentoService::class)->confirmar(
            $pagamento,
            ['amount' => $valorPago ?? (float) $pagamento->valor]
        );
    }

    public function test_iniciar_subscricao_durante_o_trial_nao_bloqueia_a_empresa(): void
    {
        $empresa = $this->criarEmpresa('200000001', 'trial');
        $plano = $this->criarPlano('basico-trial');

        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->assertSame('pendente', $pagamento->estado);
        $this->assertSame('trial', $empresa->fresh()->estado_subscricao);
    }

    public function test_iniciar_subscricao_sem_acesso_marca_a_empresa_como_pendente(): void
    {
        $empresa = $this->criarEmpresa('200000002', 'expirada');
        $plano = $this->criarPlano('basico-expirada');

        app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->assertSame('pendente', $empresa->fresh()->estado_subscricao);
    }

    public function test_iniciar_subscricao_duas_vezes_para_o_mesmo_plano_reutiliza_a_referencia(): void
    {
        $empresa = $this->criarEmpresa('200000003');
        $plano = $this->criarPlano('basico-repetido');

        $primeiro = app(SubscricaoService::class)->iniciar($empresa, $plano);
        $segundo = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame($primeiro->referencia_externa, $segundo->referencia_externa);

        // Pagamento tem TenantScope (fail-closed): sem tenant, a contagem
        // seria sempre 0 — por isso a leitura é feita fora do tenant.
        $total = app(TenantManager::class)->semTenant(
            fn () => Pagamento::where('empresa_id', $empresa->id)->count()
        );
        $this->assertSame(1, $total);
    }

    public function test_confirmar_pagamento_ativa_subscricao_e_empresa(): void
    {
        $empresa = $this->criarEmpresa('200000004');
        $plano = $this->criarPlano('profissional');

        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        $pagamentoConfirmado = $this->confirmarComoGateway($pagamento);

        $this->assertSame('confirmado', $pagamentoConfirmado->estado);
        $this->assertSame('ativa', $pagamentoConfirmado->subscricao->fresh()->estado);
        $this->assertSame('ativa', $empresa->fresh()->estado_subscricao);
        $this->assertNotNull($empresa->fresh()->subscricao_expira_em);
    }

    public function test_confirmar_o_mesmo_pagamento_duas_vezes_e_idempotente(): void
    {
        $empresa = $this->criarEmpresa('200000005');
        $plano = $this->criarPlano('basico-idempotente');

        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->confirmarComoGateway($pagamento);
        $terminaEmPrimeiraConfirmacao = $empresa->fresh()->subscricao_expira_em;

        $this->confirmarComoGateway($pagamento->fresh());

        $this->assertEquals($terminaEmPrimeiraConfirmacao, $empresa->fresh()->subscricao_expira_em);
    }

    public function test_pagar_antes_do_fim_soma_os_dias_que_restavam(): void
    {
        $empresa = $this->criarEmpresa('200000006');
        $plano = $this->criarPlano('mensal-soma', periodoDias: 30);

        $primeiroPagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        $this->confirmarComoGateway($primeiroPagamento);

        $terminaAntes = $empresa->fresh()->subscricao_expira_em;

        $segundoPagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        $this->confirmarComoGateway($segundoPagamento);

        $terminaDepois = $empresa->fresh()->subscricao_expira_em;

        $this->assertGreaterThanOrEqual(29, $terminaAntes->diffInDays($terminaDepois));
    }

    public function test_notificacao_sem_valor_pago_e_rejeitada_e_o_pagamento_continua_pendente(): void
    {
        $empresa = $this->criarEmpresa('200000007');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $this->criarPlano('sem-valor'));

        try {
            app(PagamentoService::class)->confirmar($pagamento, []);
            $this->fail('Uma notificação sem valor pago devia ter sido rejeitada.');
        } catch (PagamentoRejeitadoException) {
            $this->assertSame('pendente', $pagamento->fresh()->estado);
            $this->assertNotSame('ativa', $empresa->fresh()->estado_subscricao);
        }
    }

    public function test_notificacao_com_valor_inferior_ao_esperado_e_rejeitada(): void
    {
        $empresa = $this->criarEmpresa('200000008');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $this->criarPlano('valor-baixo'));

        try {
            $this->confirmarComoGateway($pagamento, 5000.0);
            $this->fail('Um valor inferior ao esperado devia ter sido rejeitado.');
        } catch (PagamentoRejeitadoException) {
            $this->assertSame('pendente', $pagamento->fresh()->estado);
        }
    }

    public function test_notificacao_com_valor_superior_ao_esperado_e_aceite(): void
    {
        $empresa = $this->criarEmpresa('200000009');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $this->criarPlano('valor-alto'));

        $confirmado = $this->confirmarComoGateway($pagamento, 12000.0);

        $this->assertSame('confirmado', $confirmado->estado);
    }
}
