<?php

namespace Modules\Subscricoes\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\Gateways\ProxyPayGateway;
use Modules\Subscricoes\Services\PagamentoService;
use Modules\Subscricoes\Services\SubscricaoService;
use Tests\TestCase;

class ConfirmacaoManualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    protected function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $plano = Plano::create(['nome' => 'Plano '.$nif, 'slug' => 'plano-'.$nif, 'preco' => 9900, 'periodo_dias' => 30]);

        return [$empresa->fresh(), $plano];
    }

    protected function pagamentoPendente(string $nif): Pagamento
    {
        [$empresa, $plano] = $this->cenario($nif);

        return app(SubscricaoService::class)->iniciar($empresa, $plano);
    }

    protected function opcoes(Pagamento $pagamento, User $operador, array $extra = []): array
    {
        return array_merge([
            'pagamento' => $pagamento->id,
            '--operador' => $operador->email,
            '--valor' => '9900.00',
            '--comprovativo' => 'BAI-2026-10-17-00123',
            '--justificacao' => 'Transferência recebida no extrato do banco',
        ], $extra);
    }

    protected function estadoDe(Pagamento $pagamento): string
    {
        return Pagamento::withoutGlobalScopes()->findOrFail($pagamento->id)->estado;
    }

    public function test_super_admin_confirma_com_valor_comprovativo_e_justificacao(): void
    {
        $pagamento = $this->pagamentoPendente('820000001');
        $operador = User::factory()->superAdmin()->create();

        $this->artisan('subscricoes:confirmar-manual', $this->opcoes($pagamento, $operador))
            ->assertExitCode(0);

        $this->assertSame('confirmado', $this->estadoDe($pagamento));
        $this->assertSame(
            $operador->id,
            (int) Pagamento::withoutGlobalScopes()->findOrFail($pagamento->id)->confirmado_por
        );
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'pagamentos',
            'description' => 'confirmacao_manual',
        ]);
    }

    public function test_operador_que_nao_e_super_admin_e_recusado(): void
    {
        $pagamento = $this->pagamentoPendente('820000002');
        $normal = User::factory()->create();

        $this->artisan('subscricoes:confirmar-manual', $this->opcoes($pagamento, $normal))
            ->assertExitCode(1);

        $this->assertSame('pendente', $this->estadoDe($pagamento));
    }

    public function test_justificacao_curta_e_recusada(): void
    {
        $pagamento = $this->pagamentoPendente('820000003');
        $operador = User::factory()->superAdmin()->create();

        $this->artisan('subscricoes:confirmar-manual', $this->opcoes($pagamento, $operador, [
            '--justificacao' => 'curta',
        ]))->assertExitCode(2);

        $this->assertSame('pendente', $this->estadoDe($pagamento));
    }

    public function test_sem_comprovativo_e_recusado(): void
    {
        $pagamento = $this->pagamentoPendente('820000004');
        $operador = User::factory()->superAdmin()->create();

        $this->artisan('subscricoes:confirmar-manual', $this->opcoes($pagamento, $operador, [
            '--comprovativo' => '',
        ]))->assertExitCode(2);

        $this->assertSame('pendente', $this->estadoDe($pagamento));
    }

    public function test_valor_divergente_e_recusado_e_fica_auditado(): void
    {
        $pagamento = $this->pagamentoPendente('820000005');
        $operador = User::factory()->superAdmin()->create();

        $this->artisan('subscricoes:confirmar-manual', $this->opcoes($pagamento, $operador, [
            '--valor' => '9899.00',
        ]))->assertExitCode(1);

        $this->assertSame('pendente', $this->estadoDe($pagamento));
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'pagamentos',
            'description' => 'confirmacao_manual_recusada',
        ]);
    }

    public function test_nao_permite_repetir_a_confirmacao_manual(): void
    {
        $pagamento = $this->pagamentoPendente('820000006');
        $operador = User::factory()->superAdmin()->create();
        $opcoes = $this->opcoes($pagamento, $operador);

        $this->artisan('subscricoes:confirmar-manual', $opcoes)->assertExitCode(0);
        $this->artisan('subscricoes:confirmar-manual', $opcoes)->assertExitCode(1);

        $this->assertSame(
            1,
            DB::table('activity_log')->where('description', 'confirmacao_manual')->count()
        );
    }

    public function test_pagamento_recebido_de_renovacao_cancelada_pode_ser_aplicado_manualmente(): void
    {
        [$empresa, $plano] = $this->cenario('820000007');
        $servico = app(PagamentoService::class);

        $inicial = app(SubscricaoService::class)->iniciar($empresa, $plano);
        $servico->confirmar($inicial, [], null, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();
        app(TenantManager::class)->set($empresa->id);

        $renovacao = app(SubscricaoService::class)->gerarRenovacao($subscricao);
        app(SubscricaoService::class)->cancelarRenovacao($subscricao);

        $this->assertSame('expirado', $this->estadoDe($renovacao));
        $expiracaoAntes = $empresa->fresh()->subscricao_expira_em;

        // A via automática continua a recusar...
        try {
            $servico->confirmar($renovacao->fresh(), [], null, '9900.00');
            $this->fail('A confirmação automática não pode aplicar uma renovação cancelada.');
        } catch (DomainException) {
            // esperado
        }

        // ...mas o dinheiro foi recebido: um operador autorizado aplica-o.
        $operador = User::factory()->superAdmin()->create();
        $confirmado = $servico->confirmar($renovacao->fresh(), [], $operador, '9900.00');

        $this->assertSame('confirmado', $confirmado->estado);
        $this->assertTrue($empresa->fresh()->subscricao_expira_em->greaterThan($expiracaoAntes));
        $this->assertFalse(
            (bool) Subscricao::withoutGlobalScopes()->findOrFail($subscricao->id)->renovacao_automatica,
            'A renovação automática continua cancelada.'
        );
    }

    public function test_em_producao_nao_cria_cobranca_com_proxypay_sem_entidade(): void
    {
        config([
            'subscricoes.gateways.proxypay.api_key' => 'chave',
            'subscricoes.gateways.proxypay.entity_id' => null,
            'subscricoes.gateways.proxypay.base_url' => 'https://api.proxypay.co.ao',
        ]);
        $this->app['env'] = 'production';
        $this->app->singleton(GatewayPagamentoInterface::class, fn () => new ProxyPayGateway);
        $this->app->forgetInstance(SubscricaoService::class);

        [$empresa, $plano] = $this->cenario('820000008');

        try {
            app(SubscricaoService::class)->iniciar($empresa, $plano);
            $this->fail('Devia ter bloqueado a cobrança com a configuração inválida.');
        } catch (GatewayPagamentoException $e) {
            $this->assertStringContainsString('PROXYPAY_ENTITY_ID', $e->getMessage());
        }

        $this->assertSame(0, Pagamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
    }
}
