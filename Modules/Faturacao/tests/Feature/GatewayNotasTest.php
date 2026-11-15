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
