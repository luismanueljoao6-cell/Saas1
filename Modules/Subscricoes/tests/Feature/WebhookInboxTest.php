<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Models\WebhookEvento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\Gateways\ProxyPayGateway;
use Modules\Subscricoes\Services\SubscricaoService;
use Tests\TestCase;

class GatewayRenovacaoFalso implements GatewayPagamentoInterface
{
    public function identificador(): string
    {
        return 'proxypay';
    }

    public function gerarReferencia(Pagamento $pagamento): array
    {
        return ['referencia_externa' => '9'.str_pad((string) $pagamento->id, 8, '0', STR_PAD_LEFT), 'payload' => ['entity_id' => '12345']];
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

class WebhookInboxTest extends TestCase
{
    use RefreshDatabase;

    private const SEGREDO = 'chave-de-teste';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscricoes.gateway' => 'proxypay',
            'subscricoes.gateways.proxypay.api_key' => self::SEGREDO,
            'subscricoes.gateways.proxypay.entity_id' => '12345',
        ]);
    }

    protected function pagamentoPendente(string $nif, string $referencia): Pagamento
    {
        return app(TenantManager::class)->semTenant(function () use ($nif, $referencia) {
            $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
            $plano = Plano::create(['nome' => 'Plano '.$nif, 'slug' => 'plano-'.$nif, 'preco' => 9900, 'periodo_dias' => 30]);

            $subscricao = Subscricao::create([
                'empresa_id' => $empresa->id,
                'plano_id' => $plano->id,
                'periodo_dias' => 30,
                'preco' => 9900,
                'moeda' => 'AOA',
                'limites' => [],
                'servicos' => [],
                'estado' => 'pendente',
                'renovacao_automatica' => true,
            ]);

            return Pagamento::create([
                'empresa_id' => $empresa->id,
                'subscricao_id' => $subscricao->id,
                'gateway' => 'proxypay',
                'referencia_externa' => $referencia,
                'valor' => '9900.00',
                'moeda' => 'AOA',
                'estado' => 'pendente',
                'payload_bruto' => ['entity_id' => '12345'],
                'expira_em' => now()->addDays(3),
            ]);
        });
    }

    protected function enviar(string $corpo, ?string $assinatura = null)
    {
        $assinatura ??= hash_hmac('sha256', $corpo, self::SEGREDO);

        return $this->call('POST', '/api/webhooks/proxypay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $assinatura,
        ], $corpo);
    }

    public function test_webhook_valido_e_guardado_antes_de_ser_processado(): void
    {
        $pagamento = $this->pagamentoPendente('820000001', '123456789');

        $this->enviar('{"reference_id":"123456789","amount":"9900.00"}')->assertOk();

        $evento = WebhookEvento::query()->firstOrFail();
        $this->assertSame('123456789', $evento->referencia_externa);
        $this->assertSame(WebhookEvento::PROCESSADO, $evento->estado);
        $this->assertSame('confirmado', Pagamento::withoutGlobalScopes()->find($pagamento->id)->estado);
    }

    public function test_webhook_duplicado_nao_duplica_evento_nem_estende_a_subscricao(): void
    {
        $pagamento = $this->pagamentoPendente('820000002', '123456790');
        $corpo = '{"reference_id":"123456790","amount":"9900.00"}';

        $this->enviar($corpo)->assertOk();
        $terminaEm = Subscricao::withoutGlobalScopes()->find($pagamento->subscricao_id)->termina_em;

        $this->enviar($corpo)->assertOk();

        $this->assertSame(1, WebhookEvento::query()->count());
        $this->assertSame(2, WebhookEvento::query()->first()->recebimentos);
        $this->assertEquals(
            $terminaEm,
            Subscricao::withoutGlobalScopes()->find($pagamento->subscricao_id)->termina_em
        );
    }

    public function test_webhook_autenticado_mas_ilegivel_fica_guardado_para_revisao(): void
    {
        $this->enviar('{"sem_referencia":true}')->assertOk();

        $evento = WebhookEvento::query()->firstOrFail();
        $this->assertSame(WebhookEvento::ILEGIVEL, $evento->estado);
        $this->assertNotEmpty($evento->motivo_falha);
        $this->assertStringContainsString('sem_referencia', $evento->corpo_bruto);
    }

    public function test_webhook_com_assinatura_invalida_e_rejeitado_e_nao_e_guardado(): void
    {
        $this->enviar('{"reference_id":"1"}', str_repeat('0', 64))->assertStatus(401);

        $this->assertSame(0, WebhookEvento::query()->count());
    }

    public function test_reconciliacao_reprocessa_evento_guardado_e_nao_processado(): void
    {
        $pagamento = $this->pagamentoPendente('820000003', '123456791');

        $evento = WebhookEvento::query()->create([
            'gateway' => 'proxypay',
            'chave_evento' => hash('sha256', 'teste-reconciliacao'),
            'corpo_bruto' => '{"reference_id":"123456791","amount":"9900.00"}',
            'estado' => WebhookEvento::RECEBIDO,
        ]);
        WebhookEvento::query()->whereKey($evento->id)->update(['updated_at' => now()->subHour()]);

        Artisan::call('subscricoes:reconciliar');

        $this->assertSame('confirmado', Pagamento::withoutGlobalScopes()->find($pagamento->id)->estado);
        $this->assertSame(WebhookEvento::PROCESSADO, $evento->fresh()->estado);
    }

    public function test_gateway_proxypay_exige_entidade_configurada_antes_de_cobrar(): void
    {
        config(['subscricoes.gateways.proxypay.entity_id' => null]);
        $pagamento = $this->pagamentoPendente('820000004', '123456792');

        $this->expectException(GatewayPagamentoException::class);

        (new ProxyPayGateway)->gerarReferencia($pagamento);
    }

    public function test_pagamento_expoe_a_entidade_multicaixa(): void
    {
        $pagamento = $this->pagamentoPendente('820000005', '123456793');

        $this->assertSame('12345', $pagamento->entidade());
    }

    public function test_renovacao_mantem_o_preco_contratado_mesmo_que_o_plano_mude(): void
    {
        $this->app->singleton(GatewayPagamentoInterface::class, fn () => new GatewayRenovacaoFalso);
        $pagamento = $this->pagamentoPendente('820000006', '123456794');

        $subscricao = Subscricao::withoutGlobalScopes()->find($pagamento->subscricao_id);
        $subscricao->update(['estado' => 'ativa', 'inicio_em' => now()->subDays(27), 'termina_em' => now()->addDays(3)]);
        Pagamento::withoutGlobalScopes()->whereKey($pagamento->id)->update(['estado' => 'confirmado']);
        Plano::query()->whereKey($subscricao->plano_id)->update(['preco' => 15000]);

        $renovacao = app(SubscricaoService::class)->gerarRenovacao($subscricao->fresh());

        $this->assertSame('9900.00', (string) $renovacao->valor);
    }
}
