<?php

namespace Modules\Subscricoes\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Core\Models\Empresa;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\PagamentoService;
use Modules\Subscricoes\Services\SubscricaoService;
use RuntimeException;
use Tests\TestCase;

class SegurancaPagamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function usarGateway(bool $falha = false): void
    {
        $this->app->singleton(GatewayPagamentoInterface::class, fn () => new class($falha) implements GatewayPagamentoInterface
        {
            public function __construct(private bool $falha) {}

            public function identificador(): string
            {
                return 'gateway-teste';
            }

            public function gerarReferencia(Pagamento $pagamento): array
            {
                if ($this->falha) {
                    throw new RuntimeException('gateway em baixo');
                }

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

    protected function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $plano = Plano::create(['nome' => 'Plano '.$nif, 'slug' => 'plano-'.$nif, 'preco' => 9900, 'periodo_dias' => 30]);

        return [$empresa->fresh(), $plano];
    }

    public function test_valor_pago_inferior_ao_devido_nao_confirma_nada(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000001');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        try {
            app(PagamentoService::class)->confirmar($pagamento, [], null, '9899.99');
            $this->fail('Devia ter rejeitado o subpagamento.');
        } catch (DomainException) {
            // esperado
        }

        $this->assertSame('pendente', Pagamento::withoutGlobalScopes()->find($pagamento->id)->estado);
        $this->assertSame('trial', $empresa->fresh()->estado_subscricao);
    }

    public function test_valor_exato_confirma_e_ativa_a_empresa(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000002');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $confirmado = app(PagamentoService::class)->confirmar($pagamento, [], null, '9900.00');

        $this->assertSame('confirmado', $confirmado->estado);
        $this->assertSame('ativa', $empresa->fresh()->estado_subscricao);
    }

    public function test_falha_do_gateway_nao_deixa_pagamento_pendente_sem_referencia(): void
    {
        $this->usarGateway(falha: true);
        [$empresa, $plano] = $this->cenario('810000003');

        try {
            app(SubscricaoService::class)->iniciar($empresa, $plano);
            $this->fail('Devia ter propagado a falha do gateway.');
        } catch (RuntimeException) {
            // esperado
        }

        $pagamento = Pagamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->first();

        $this->assertSame('expirado', $pagamento->estado);
        $this->assertSame('cancelada', Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->first()->estado);
    }
}
