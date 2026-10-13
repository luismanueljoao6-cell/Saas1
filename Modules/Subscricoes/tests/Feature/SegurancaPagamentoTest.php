<?php

namespace Modules\Subscricoes\Tests\Feature;

use Closure;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Jobs\ProcessarPagamentoConfirmadoJob;
use Modules\Subscricoes\Jobs\VerificarSubscricoesExpiradasJob;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\Gateways\ProxyPayGateway;
use Modules\Subscricoes\Services\PagamentoService;
use Modules\Subscricoes\Services\SubscricaoService;
use RuntimeException;
use Tests\TestCase;

class GatewayPagamentoTeste implements GatewayPagamentoInterface
{
    public function __construct(private bool $falha = false, private ?Closure $duranteGeracao = null, private ?Closure $aoCancelar = null) {}

    public function identificador(): string
    {
        return 'gateway-teste';
    }

    public function gerarReferencia(Pagamento $pagamento): array
    {
        $this->duranteGeracao?->__invoke($pagamento);

        if ($this->falha) {
            throw new RuntimeException('gateway em baixo');
        }

        return ['referencia_externa' => 'REF-'.$pagamento->id, 'payload' => ['ok' => true]];
    }

            public function cancelarReferencia(Pagamento $pagamento): void
            {
                $this->aoCancelar?->__invoke($pagamento);
            }

    public function validarPedidoWebhook(Request $request): bool
    {
        return true;
    }

    public function interpretarNotificacao(Request $request): array
    {
        return ['referencia_externa' => 'x', 'estado' => 'confirmado', 'payload' => []];
    }
}

class SegurancaPagamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function usarGateway(bool $falha = false): void
    {
        $this->app->singleton(
            GatewayPagamentoInterface::class,
            fn () => new GatewayPagamentoTeste($falha)
        );
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

    public function test_webhook_atrasado_nao_ativa_pagamento_expirado(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000015');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        $pagamento->update(['estado' => 'expirado']);

        try {
            app(PagamentoService::class)->confirmar($pagamento->fresh(), [], null, '9900.00');
            $this->fail('Um webhook atrasado não deve ativar um pagamento expirado.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('reconciliação manual', $e->getMessage());
        }

        $this->assertSame('expirado', $pagamento->fresh()->estado);
        $this->assertSame('trial', $empresa->fresh()->estado_subscricao);
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
        $this->assertSame('trial', $empresa->fresh()->estado_subscricao);
    }

    public function test_webhook_sem_valor_pago_nao_confirma(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000004');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        (new ProcessarPagamentoConfirmadoJob('gateway-teste', (string) $pagamento->referencia_externa, [], null))
            ->handle(app(PagamentoService::class));

        $this->assertSame('pendente', Pagamento::withoutGlobalScopes()->find($pagamento->id)->estado);
        $this->assertSame('trial', $empresa->fresh()->estado_subscricao);
    }

    public function test_webhook_com_valor_exato_confirma(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000005');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        (new ProcessarPagamentoConfirmadoJob('gateway-teste', (string) $pagamento->referencia_externa, [], '9900.00'))
            ->handle(app(PagamentoService::class));

        $this->assertSame('confirmado', Pagamento::withoutGlobalScopes()->find($pagamento->id)->estado);
        $this->assertSame('ativa', $empresa->fresh()->estado_subscricao);
    }

    public function test_alterar_plano_nao_muda_termos_de_uma_compra_ja_iniciada(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000012');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $plano->update(['preco' => 12000, 'periodo_dias' => 60]);

        $this->assertSame('9900.00', (string) $pagamento->fresh()->valor);

        app(PagamentoService::class)->confirmar(
            $pagamento,
            [],
            null,
            '9900.00'
        );

        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $empresa->fresh()->subscricao_expira_em->toDateString()
        );
    }

    public function test_nao_permite_iniciar_subscricao_com_plano_inativo(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000011');
        $plano->update(['ativo' => false]);

        $this->expectException(DomainException::class);

        try {
            app(SubscricaoService::class)->iniciar($empresa, $plano);
        } finally {
            $this->assertSame(
                0,
                Pagamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count()
            );
        }
    }

    public function test_confirmacao_repetida_nao_estende_duas_vezes_o_periodo_pago(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000010');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $servico = app(PagamentoService::class);
        $servico->confirmar($pagamento, [], null, '9900.00');
        $primeiraExpiracao = $empresa->fresh()->subscricao_expira_em;

        $servico->confirmar($pagamento, [], null, '9900.00');
        $segundaExpiracao = $empresa->fresh()->subscricao_expira_em;

        $this->assertSame(
            $primeiraExpiracao->toDateTimeString(),
            $segundaExpiracao->toDateTimeString()
        );
        $this->assertSame(
            1,
            Pagamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('estado', 'confirmado')->count()
        );
    }

    public function test_iniciar_reutiliza_referencia_pendente_do_mesmo_plano(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000006');

        $primeiro = app(SubscricaoService::class)->iniciar($empresa, $plano);
        $segundo = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->assertSame('trial', $empresa->fresh()->estado_subscricao);
        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame(
            1,
            Pagamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count()
        );
        $this->assertSame(
            1,
            Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count()
        );
    }

    public function test_valor_recebido_em_notacao_cientifica_e_rejeitado(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000007');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->expectException(DomainException::class);

        try {
            app(PagamentoService::class)->confirmar($pagamento, [], null, '9.9e3');
        } finally {
            $this->assertSame(
                'pendente',
                Pagamento::withoutGlobalScopes()->find($pagamento->id)->estado
            );
        }
    }

    public function test_nao_permite_cobrancas_pendentes_para_planos_diferentes(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000009');
        $outroPlano = Plano::create([
            'nome' => 'Plano alternativo',
            'slug' => 'plano-alternativo-810000009',
            'preco' => 15000,
            'periodo_dias' => 30,
        ]);

        $primeiro = app(SubscricaoService::class)->iniciar($empresa, $plano);

        try {
            app(SubscricaoService::class)->iniciar($empresa, $outroPlano);
            $this->fail('Devia impedir uma segunda cobrança para outro plano.');
        } catch (DomainException) {
            // esperado: não permitir duas cobranças pendentes concorrentes.
        }

        $this->assertSame(
            1,
            Pagamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count()
        );
        $this->assertSame(
            $primeiro->id,
            Pagamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->first()->id
        );
    }

    public function test_valor_recebido_superior_ao_devido_e_rejeitado(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000008');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        try {
            app(PagamentoService::class)->confirmar($pagamento, [], null, '9900.01');
            $this->fail('Devia ter rejeitado um valor superior ao devido.');
        } catch (DomainException) {
            // esperado: o excedente não pode ser aceite sem política de crédito/reembolso.
        }

        $this->assertSame('pendente', Pagamento::withoutGlobalScopes()->find($pagamento->id)->estado);
    }

    public function test_webhook_proxypay_valida_hmac_sha256_do_corpo_original(): void
    {
        config(['subscricoes.gateways.proxypay.api_key' => 'chave-de-teste']);
        $gateway = new ProxyPayGateway;
        $corpo = '{"reference_id":123456789,"amount":"9900.00"}';
        $request = Request::create('/api/webhooks/proxypay', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $corpo);
        $request->headers->set('X-Signature', hash_hmac('sha256', $corpo, 'chave-de-teste'));

        $this->assertTrue($gateway->validarPedidoWebhook($request));
    }

    public function test_webhook_proxypay_rejeita_assinatura_invalida_e_token_na_url(): void
    {
        config(['subscricoes.gateways.proxypay.api_key' => 'chave-de-teste']);
        $gateway = new ProxyPayGateway;
        $corpo = '{"reference_id":123456789,"amount":"9900.00"}';

        $assinaturaInvalida = Request::create('/api/webhooks/proxypay', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $corpo);
        $assinaturaInvalida->headers->set('X-Signature', str_repeat('0', 64));
        $this->assertFalse($gateway->validarPedidoWebhook($assinaturaInvalida));

        $tokenNaUrl = Request::create('/api/webhooks/proxypay?token=chave-de-teste', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $corpo);
        $this->assertFalse($gateway->validarPedidoWebhook($tokenNaUrl));
    }

    public function test_cancelar_renovacao_impede_gerar_nova_referencia_automatica(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000013');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        app(PagamentoService::class)->confirmar($pagamento, [], null, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();
        app(TenantManager::class)->set($empresa->id);
        app(SubscricaoService::class)->cancelarRenovacao($subscricao);

        $this->expectException(DomainException::class);
        try {
            app(SubscricaoService::class)->gerarRenovacao($subscricao);
        } finally {
            $this->assertSame(
                1,
                Pagamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count(),
                'Não deve ser criada uma nova cobrança depois do cancelamento da renovação.'
            );
        }
    }

    public function test_cancelar_renovacao_invalida_referencia_pendente_e_webhook_nao_renova(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000018');

        $pagamentoInicial = app(SubscricaoService::class)->iniciar($empresa, $plano);
        app(PagamentoService::class)->confirmar($pagamentoInicial, [], null, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->firstOrFail();
        app(TenantManager::class)->set($empresa->id);

        $pagamentoRenovacao = app(SubscricaoService::class)->gerarRenovacao($subscricao);
        $expiracaoOriginal = $empresa->fresh()->subscricao_expira_em;

        app(SubscricaoService::class)->cancelarRenovacao($subscricao);

        $this->assertSame(
            'expirado',
            Pagamento::withoutGlobalScopes()->findOrFail($pagamentoRenovacao->id)->estado
        );

        try {
            app(PagamentoService::class)->confirmar(
                $pagamentoRenovacao->fresh(),
                [],
                null,
                '9900.00'
            );
            $this->fail('Uma referência de renovação cancelada não pode renovar a subscrição.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('não pode ser confirmado automaticamente', $e->getMessage());
        }

        $this->assertSame(
            $expiracaoOriginal->toDateTimeString(),
            $empresa->fresh()->subscricao_expira_em->toDateTimeString()
        );
    }

    public function test_cancelamento_durante_chamada_ao_gateway_impede_envio_da_referencia(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000020');
        $pagamentoInicial = app(SubscricaoService::class)->iniciar($empresa, $plano);
        app(PagamentoService::class)->confirmar($pagamentoInicial, [], null, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->firstOrFail();
        $subscricao->update(['termina_em' => now()->addDays(2)]);

        $this->app->singleton(
            GatewayPagamentoInterface::class,
            fn () => new GatewayPagamentoTeste(false, function (Pagamento $pagamento) use ($subscricao): void {
                app(SubscricaoService::class)->cancelarRenovacao($subscricao->fresh());
            })
        );

        Notification::fake();

        $renovacao = app(SubscricaoService::class)->gerarRenovacao($subscricao->fresh());

        $this->assertSame('expirado', Pagamento::withoutGlobalScopes()->findOrFail($renovacao->id)->estado);
        Notification::assertNothingSent();
    }

    public function test_nao_permite_retomar_renovacao_de_subscricao_vencida(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000014');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        app(PagamentoService::class)->confirmar($pagamento, [], null, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();
        app(TenantManager::class)->set($empresa->id);
        $subscricao->update(['termina_em' => now()->subDay()]);

        $this->expectException(DomainException::class);
        app(SubscricaoService::class)->retomarRenovacao($subscricao);
    }

    public function test_rotina_nao_cancela_subscricao_pendente_com_pagamento_confirmado(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000017');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        // Simula o estado após a confirmação do pagamento, mas antes da
        // sincronização da subscrição, para garantir que a limpeza não o
        // transforma num cancelamento irreversível.
        Pagamento::withoutGlobalScopes()
            ->whereKey($pagamento->id)
            ->update(['estado' => 'confirmado']);

        $subscricao = Subscricao::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->firstOrFail();

        app(VerificarSubscricoesExpiradasJob::class)->handle(
            app(TenantManager::class),
            app(TenantService::class)
        );

        $this->assertSame(
            'pendente',
            Subscricao::withoutGlobalScopes()->findOrFail($subscricao->id)->estado
        );
    }

    public function test_expiracao_de_subscricao_antiga_nao_suspende_empresa_com_outra_subscricao_valida(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000016');
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        app(PagamentoService::class)->confirmar($pagamento, [], null, '9900.00');

        $tenantManager = app(TenantManager::class);
        $subscricaoAntiga = Subscricao::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->firstOrFail();
        $subscricaoAntiga->update(['termina_em' => now()->subDay()]);

        $tenantManager->semTenant(fn () => Subscricao::create([
            'empresa_id' => $empresa->id,
            'plano_id' => $plano->id,
            'periodo_dias' => 30,
            'limites' => $plano->limites ?? [],
            'servicos' => $plano->servicos ?? [],
            'estado' => 'ativa',
            'inicio_em' => now()->subDay(),
            'termina_em' => now()->addDays(20),
            'renovacao_automatica' => true,
        ]));

        app(VerificarSubscricoesExpiradasJob::class)->handle(
            $tenantManager,
            app(TenantService::class)
        );

        $this->assertSame('ativa', $empresa->fresh()->estado_subscricao);
        $this->assertSame(
            'expirada',
            Subscricao::withoutGlobalScopes()->findOrFail($subscricaoAntiga->id)->estado
        );
    }

    public function test_subscricao_vencida_deixa_de_conceder_limites_do_plano_mesmo_antes_do_job(): void
    {
        $this->usarGateway();
        [$empresa, $plano] = $this->cenario('810000019');
        $plano->update(['limites' => ['max_utilizadores' => 99]]);

        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        app(PagamentoService::class)->confirmar($pagamento, [], null, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->firstOrFail();
        $subscricao->update([
            'limites' => ['max_utilizadores' => 99],
            'termina_em' => now()->subSecond(),
        ]);

        config(['subscricoes.limites_sem_plano.max_utilizadores' => 3]);

        $limite = app(LimitesDaEmpresa::class)
            ->limite($empresa->fresh(), 'max_utilizadores');

        $this->assertSame(
            3,
            $limite,
            'Uma subscrição vencida não deve manter os limites pagos só porque o job de expiração ainda não correu.'
        );
    }
}
