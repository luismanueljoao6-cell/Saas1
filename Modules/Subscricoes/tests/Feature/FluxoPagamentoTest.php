<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Core\Models\Empresa;
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

        $contador = 0;

        // Gateway falso: evita qualquer chamada HTTP real durante os testes
        // e torna o comportamento determinístico. Referência incremental
        // (não baseada no id do Pagamento) para expor, sem ambiguidade, o
        // teste de reutilização de referência pendente.
        $this->app->bind(GatewayPagamentoInterface::class, fn () => new class($contador) implements GatewayPagamentoInterface
        {
            public function __construct(private int &$contador) {}

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

    public function test_iniciar_subscricao_durante_o_trial_nao_bloqueia_a_empresa(): void
    {
        // Este é o comportamento corrigido: uma empresa em trial (com
        // acesso) que começa a subscrever um plano NÃO fica bloqueada só
        // por isso — só passa a 'pendente' se já não tivesse acesso.
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
        $this->assertSame(1, Pagamento::where('empresa_id', $empresa->id)->count());
    }

    public function test_confirmar_pagamento_ativa_subscricao_e_empresa(): void
    {
        $empresa = $this->criarEmpresa('200000004');
        $plano = $this->criarPlano('profissional');

        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        $pagamentoConfirmado = app(PagamentoService::class)->confirmar($pagamento);

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

        $servico = app(PagamentoService::class);
        $servico->confirmar($pagamento);
        $terminaEmPrimeiraConfirmacao = $empresa->fresh()->subscricao_expira_em;

        $servico->confirmar($pagamento->fresh());

        $this->assertEquals($terminaEmPrimeiraConfirmacao, $empresa->fresh()->subscricao_expira_em);
    }

    public function test_pagar_antes_do_fim_soma_os_dias_que_restavam(): void
    {
        $empresa = $this->criarEmpresa('200000006');
        $plano = $this->criarPlano('mensal-soma', periodoDias: 30);

        $primeiroPagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        app(PagamentoService::class)->confirmar($primeiroPagamento);

        $terminaAntes = $empresa->fresh()->subscricao_expira_em;

        // Paga a renovação antes do fim do período atual — 10 dias antes,
        // por exemplo — e os 10 dias restantes não podem perder-se.
        $segundoPagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        app(PagamentoService::class)->confirmar($segundoPagamento);

        $terminaDepois = $empresa->fresh()->subscricao_expira_em;

        // 30 dias novos a somar aos que já lá estavam: a diferença tem de
        // ser (muito perto de) 30 dias, nunca menos.
        $this->assertGreaterThanOrEqual(29, $terminaAntes->diffInDays($terminaDepois));
    }
}
