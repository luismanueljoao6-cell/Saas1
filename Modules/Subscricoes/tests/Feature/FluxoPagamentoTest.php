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

        // Gateway falso: evita qualquer chamada HTTP real durante os testes
        // e torna o comportamento determinístico.
        $this->app->bind(GatewayPagamentoInterface::class, fn () => new class implements GatewayPagamentoInterface
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
                return ['referencia_externa' => $request->input('reference'), 'estado' => 'confirmado', 'payload' => []];
            }
        });
    }

    public function test_iniciar_subscricao_gera_pagamento_pendente_e_marca_empresa_como_pendente(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Alfaiataria Teste', 'nif' => '200000001']);
        $plano = Plano::create(['nome' => 'Básico', 'slug' => 'basico-teste', 'preco' => 9900, 'periodo_dias' => 30]);

        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->assertSame('pendente', $pagamento->estado);
        $this->assertNotNull($pagamento->referencia_externa);
        $this->assertSame('pendente', $empresa->fresh()->estado_subscricao);
    }

    public function test_confirmar_pagamento_ativa_subscricao_e_empresa(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Estúdio Teste', 'nif' => '200000002']);
        $plano = Plano::create(['nome' => 'Profissional', 'slug' => 'pro-teste', 'preco' => 24900, 'periodo_dias' => 30]);

        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $pagamentoConfirmado = app(PagamentoService::class)->confirmar($pagamento);

        $this->assertSame('confirmado', $pagamentoConfirmado->estado);
        $this->assertSame('ativa', $pagamentoConfirmado->subscricao->fresh()->estado);
        $this->assertSame('ativa', $empresa->fresh()->estado_subscricao);
        $this->assertNotNull($empresa->fresh()->subscricao_expira_em);
    }

    public function test_confirmar_o_mesmo_pagamento_duas_vezes_e_idempotente(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Gravadora Teste', 'nif' => '200000003']);
        $plano = Plano::create(['nome' => 'Básico', 'slug' => 'basico-teste-2', 'preco' => 9900, 'periodo_dias' => 30]);

        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $servico = app(PagamentoService::class);
        $servico->confirmar($pagamento);
        $terminaEmPrimeiraConfirmacao = $empresa->fresh()->subscricao_expira_em;

        // Segunda confirmação (ex.: gateway reenviou o webhook) não deve
        // avançar novamente a data de expiração.
        $servico->confirmar($pagamento->fresh());

        $this->assertEquals($terminaEmPrimeiraConfirmacao, $empresa->fresh()->subscricao_expira_em);
    }
}
