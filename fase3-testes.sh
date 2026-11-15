#!/usr/bin/env bash
# Fase 3 — testes de regressão (colar na raiz do repositório, no branch fix/fase3-faturacao-nucleo)
set -euo pipefail
cat > Modules/Faturacao/tests/Feature/GatewayNotasTest.php <<'PHPEOF'
<?php

namespace Modules\Faturacao\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Contracts\FaturacaoGateway;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Tests\TestCase;

/**
 * Fase 3 da auditoria: notas de crédito e débito têm de poder ser pedidas pelo mesmo
 * contrato público (FaturacaoGateway) que os módulos verticais já usam para faturas e
 * recibos, sem aceder aos serviços internos da Faturação.
 */
class GatewayNotasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-gateway-notas-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        config(['faturacao.chave_privada_path' => $caminho]);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));
    }

    public function test_nota_de_credito_parcial_pelo_gateway_reduz_o_saldo(): void
    {
        [$empresa, $cliente] = $this->cenario('610000021');
        $gateway = app(FaturacaoGateway::class);
        $fatura = $this->faturaEmitida($empresa, $cliente); // total 1140,00

        $rascunho = $gateway->criarNotaCredito($empresa->id, $cliente->id, $fatura->id, 'Desconto comercial', [
            ['descricao' => 'Desconto', 'quantidade' => 1, 'preco_unitario' => '500.00', 'taxa_iva' => '14'],
        ]);

        $this->assertSame('rascunho', $rascunho->estado);

        $nota = $gateway->emitirNota($rascunho);

        $this->assertSame('emitida', $nota->estado);
        $this->assertSame('credito', $nota->tipo);
        $this->assertSame('570.00', (string) $nota->valor_total);
        $this->assertSame(
            ['recebido' => 0, 'creditado' => 57000, 'abatido' => 0, 'saldo' => 57000],
            $gateway->resumoDaFatura($fatura->fresh()),
        );
    }

    public function test_anulacao_total_pelo_gateway_so_pode_ser_feita_uma_vez(): void
    {
        [$empresa, $cliente] = $this->cenario('610000022');
        $gateway = app(FaturacaoGateway::class);
        $fatura = $this->faturaEmitida($empresa, $cliente);

        $nota = $gateway->emitirNota($gateway->anularFatura($fatura, 'Erro de faturação'));

        $this->assertSame('emitida', $nota->estado);
        $this->assertSame('1140.00', (string) $nota->valor_total);
        $this->assertSame(0, $gateway->saldoEmCentimos($fatura->fresh()));

        $this->assertRecusado(fn () => $gateway->anularFatura($fatura->fresh(), 'Segunda tentativa'));
    }

    public function test_nota_de_debito_pelo_gateway_nao_liquida_iva_e_aumenta_o_saldo(): void
    {
        [$empresa, $cliente] = $this->cenario('610000023');
        $gateway = app(FaturacaoGateway::class);
        $fatura = $this->faturaEmitida($empresa, $cliente);

        $nota = $gateway->emitirNota($gateway->criarNotaDebito($empresa->id, $cliente->id, 'Juros de mora', [
            ['descricao' => 'Juros de mora', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => '0'],
        ], $fatura->id));

        $this->assertSame('debito', $nota->tipo);
        $this->assertSame('0.00', (string) $nota->valor_iva);
        $this->assertSame('100.00', (string) $nota->valor_total);
        $this->assertSame(124000, $gateway->saldoEmCentimos($fatura->fresh()));
    }

    public function test_gateway_recusa_nota_de_credito_sobre_fatura_de_outra_empresa(): void
    {
        [$a, $clienteA] = $this->cenario('610000024');
        [$b, $clienteB] = $this->cenario('610000025');
        $gateway = app(FaturacaoGateway::class);
        $faturaA = $this->faturaEmitida($a, $clienteA);
        $antes = DB::table('notas_credito_debito')->count();

        $this->assertRecusado(fn () => $gateway->criarNotaCredito($b->id, $clienteB->id, $faturaA->id, 'Tentativa', [
            ['descricao' => 'Correção', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => '14'],
        ]));

        $this->assertSame($antes, DB::table('notas_credito_debito')->count(), 'Nenhuma nota pode ter sido criada.');
    }

    /** @return array{0: Empresa, 1: Cliente} */
    private function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif, 'nif' => null]);

        return [$empresa, $cliente];
    }

    /** Fatura emitida de 1140,00 (1000,00 + 14%). */
    private function faturaEmitida(Empresa $empresa, Cliente $cliente): Fatura
    {
        $gateway = app(FaturacaoGateway::class);

        return $gateway->emitirFatura($gateway->criarRascunho($empresa, $cliente, [[
            'descricao' => 'Serviço',
            'quantidade' => 1,
            'preco_unitario' => '1000.00',
            'taxa_iva' => '14',
        ]]));
    }

    private function assertRecusado(callable $acao): void
    {
        try {
            $acao();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('O gateway devia ter recusado esta operação com uma DomainException.');
    }
}
PHPEOF
cat > Modules/Faturacao/tests/Feature/NucleoFaturacaoFase3Test.php <<'PHPEOF'
<?php

namespace Modules\Faturacao\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\OrcamentoService;
use Tests\TestCase;

/** Regressões da Fase 3 da auditoria (núcleo da Faturação): guardas de tenant e orçamentos. */
class NucleoFaturacaoFase3Test extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array<string, string|int>> */
    private function linhas(string $preco = '100.00'): array
    {
        return [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => '14', 'operation_type' => 'SG']];
    }

    /** @return array{0: Empresa, 1: Cliente} com o tenant ativo definido. */
    private function contexto(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos([Servico::Faturacao]);
        app(TenantManager::class)->set((int) $empresa->id);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif, 'nif' => null]);

        return [$empresa, $cliente];
    }

    public function test_edicao_de_rascunho_recusa_empresa_fora_do_tenant_ativo(): void
    {
        [$empresa, $cliente] = $this->contexto('620000001');
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($empresa, $cliente, $this->linhas());

        $tenant = app(TenantManager::class);
        $tenant->set((int) $empresa->id + 1000);

        try {
            $servico->atualizarRascunho($rascunho, $cliente, $this->linhas('999.00'));
            $this->fail('A edição devia ser recusada fora do tenant da fatura.');
        } catch (DomainException $e) {
            $this->assertSame('A operação não pertence à empresa do contexto atual.', $e->getMessage());
        } finally {
            $tenant->clear();
        }

        $this->assertSame('114.00', (string) Fatura::withoutGlobalScopes()->findOrFail($rascunho->id)->valor_total);
    }

    public function test_eliminacao_de_rascunho_recusa_empresa_fora_do_tenant_ativo(): void
    {
        [$empresa, $cliente] = $this->contexto('620000002');
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($empresa, $cliente, $this->linhas());

        $tenant = app(TenantManager::class);
        $tenant->set((int) $empresa->id + 1000);

        try {
            $servico->eliminarRascunho($rascunho);
            $this->fail('A eliminação devia ser recusada fora do tenant da fatura.');
        } catch (DomainException $e) {
            $this->assertSame('A operação não pertence à empresa do contexto atual.', $e->getMessage());
        } finally {
            $tenant->clear();
        }

        $this->assertNotNull(Fatura::withoutGlobalScopes()->find($rascunho->id));
    }

    public function test_orcamento_nao_pode_ser_criado_com_validade_no_passado(): void
    {
        [$empresa, $cliente] = $this->contexto('620000003');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A data de validade não pode estar no passado.');

        app(OrcamentoService::class)->criar($empresa, $cliente, $this->linhas(), today()->subDay()->toDateString(), null);
    }

    public function test_orcamento_aceite_converte_sem_tenant_ambiente(): void
    {
        [$empresa, $cliente] = $this->contexto('620000004');
        $servico = app(OrcamentoService::class);
        $orcamento = $servico->criar($empresa, $cliente, $this->linhas(), today()->addDays(5)->toDateString(), null);
        $servico->alterarEstado($orcamento, 'aceite');

        // Job ou comando: não existe tenant ambiente.
        app(TenantManager::class)->clear();

        $fatura = $servico->converter($orcamento->fresh(), $empresa, app(FaturaService::class));

        $this->assertSame('rascunho', $fatura->estado);
        $this->assertSame((int) $cliente->id, (int) $fatura->cliente_id);
    }

    public function test_duplicacao_de_orcamento_funciona_sem_tenant_ambiente(): void
    {
        [$empresa, $cliente] = $this->contexto('620000005');
        $servico = app(OrcamentoService::class);
        $origem = $servico->criar($empresa, $cliente, $this->linhas(), null, null);

        app(TenantManager::class)->clear();

        $copia = $servico->duplicar($origem->fresh(), $empresa);

        $this->assertSame('rascunho', $copia->estado);
        $this->assertSame((int) $cliente->id, (int) $copia->cliente_id);
        $this->assertNotSame($origem->numero, $copia->numero);
    }
}
PHPEOF
echo "Testes criados. Corre: vendor/bin/pint --dirty && php artisan test --filter=\"GatewayNotas|NucleoFaturacaoFase3\""
