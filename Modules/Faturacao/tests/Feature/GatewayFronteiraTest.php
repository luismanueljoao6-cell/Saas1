<?php

namespace Modules\Faturacao\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Contracts\FaturacaoGateway;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Support\Dinheiro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O FaturacaoGateway é a fronteira que o Atelier e o Estúdio usam para faturar.
 * Estes testes exercitam-no pelo seu comportamento (não só pela resolução no
 * contentor): totais exatos, o mesmo resultado venha o valor em texto ou em
 * float, pagamentos parciais, regularizações por nota de crédito e recusa de
 * clientes ou faturas de outra empresa.
 */
class GatewayFronteiraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-gateway-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        config(['faturacao.chave_privada_path' => $caminho]);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));
    }

    // ---------------------------------------------------------------------
    // Valores exatos
    // ---------------------------------------------------------------------

    public function test_totais_pelo_gateway_sao_exatos_com_valores_em_texto(): void
    {
        [$empresa, $cliente] = $this->cenario('610000001');

        $fatura = $this->emitirPeloGateway($empresa, $cliente, '19.99', '3', '14');

        $this->assertSame('59.97', (string) $fatura->valor_sem_iva);
        $this->assertSame('8.40', (string) $fatura->valor_iva);
        $this->assertSame('68.37', (string) $fatura->valor_total);
    }

    public function test_iva_e_arredondado_por_excesso_ao_centimo(): void
    {
        [$empresa, $cliente] = $this->cenario('610000002');

        // 3 × 0,10 = 0,30; IVA 14% = 0,042 → 0,05 (a AGT exige o arredondamento por excesso).
        $fatura = $this->emitirPeloGateway($empresa, $cliente, '0.10', '3', '14');

        $this->assertSame('0.30', (string) $fatura->valor_sem_iva);
        $this->assertSame('0.05', (string) $fatura->valor_iva);
        $this->assertSame('0.35', (string) $fatura->valor_total);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function valoresDificeis(): array
    {
        return [
            'meio centimo no preço' => ['1.005', '1', '14'],
            'meio centimo no total da linha' => ['33.335', '2', '14'],
            'valor binário impreciso' => ['0.285', '7', '14'],
            'o clássico 0,1 + 0,2' => ['0.10', '3', '14'],
            'quantidade fracionária grande' => ['72060.70', '47.25', '14'],
            'valor grande' => ['1234567.89', '1.5', '14'],
        ];
    }

    #[DataProvider('valoresDificeis')]
    public function test_o_total_e_a_soma_exacta_da_base_e_do_iva(string $preco, string $quantidade, string $taxa): void
    {
        [$empresa, $cliente] = $this->cenario('610000003');

        $fatura = $this->emitirPeloGateway($empresa, $cliente, $preco, $quantidade, $taxa);

        $this->assertSame(
            Dinheiro::centimos($fatura->valor_sem_iva) + Dinheiro::centimos($fatura->valor_iva),
            Dinheiro::centimos($fatura->valor_total),
        );
    }

    #[DataProvider('valoresDificeis')]
    public function test_valores_em_float_sao_recusados_pelo_gateway(string $preco, string $quantidade, string $taxa): void
    {
        [$empresa, $cliente] = $this->cenario('610000003');

        $this->expectException(InvalidArgumentException::class);

        $this->emitirPeloGateway($empresa, $cliente, (float) $preco, $quantidade, $taxa);
    }

    // ---------------------------------------------------------------------
    // Pagamentos parciais e regularizações
    // ---------------------------------------------------------------------

    public function test_pagamentos_parciais_atualizam_o_saldo_e_o_excesso_e_recusado(): void
    {
        [$empresa, $cliente] = $this->cenario('610000004');
        $gateway = app(FaturacaoGateway::class);
        $fatura = $this->emitirPeloGateway($empresa, $cliente, '1000.00', '1', '14'); // total 1140,00

        $this->assertSame(114000, $gateway->saldoEmCentimos($fatura));

        $gateway->emitirRecibo($empresa->id, $cliente->id, '400.00', 'dinheiro', $fatura->id);
        $this->assertSame(['recebido' => 40000, 'creditado' => 0, 'abatido' => 0, 'saldo' => 74000], $gateway->resumoDaFatura($fatura->fresh()));

        $gateway->emitirRecibo($empresa->id, $cliente->id, '740.00', 'transferencia', $fatura->id);
        $this->assertSame(['recebido' => 114000, 'creditado' => 0, 'abatido' => 0, 'saldo' => 0], $gateway->resumoDaFatura($fatura->fresh()));

        $this->assertRecusado(fn () => $gateway->emitirRecibo($empresa->id, $cliente->id, '0.01', 'dinheiro', $fatura->id));
    }

    public function test_nota_de_credito_parcial_reduz_o_saldo_visto_pelo_gateway(): void
    {
        [$empresa, $cliente] = $this->cenario('610000005');
        $gateway = app(FaturacaoGateway::class);
        $fatura = $this->emitirPeloGateway($empresa, $cliente, '1000.00', '1', '14'); // total 1140,00

        $notas = app(NotaCreditoDebitoService::class);
        $linha = [['descricao' => 'Desconto', 'quantidade' => 1, 'preco_unitario' => '500.00', 'taxa_iva' => '14']];
        $notas->emitir($notas->criarRascunho($empresa->id, $cliente->id, 'credito', 'Desconto comercial', $linha, $fatura->id));

        $this->assertSame(['recebido' => 0, 'creditado' => 57000, 'abatido' => 0, 'saldo' => 57000], $gateway->resumoDaFatura($fatura->fresh()));

        $gateway->emitirRecibo($empresa->id, $cliente->id, '570.00', 'dinheiro', $fatura->id);
        $this->assertSame(0, $gateway->saldoEmCentimos($fatura->fresh()));

        $this->assertRecusado(fn () => $gateway->emitirRecibo($empresa->id, $cliente->id, '0.01', 'dinheiro', $fatura->id));
    }

    // ---------------------------------------------------------------------
    // Isolamento entre empresas na fronteira
    // ---------------------------------------------------------------------

    public function test_gateway_recusa_clientes_e_faturas_de_outra_empresa_sem_deixar_rasto(): void
    {
        [$a, $clienteA] = $this->cenario('610000010');
        [$b, $clienteB] = $this->cenario('610000011');
        $gateway = app(FaturacaoGateway::class);
        $faturaA = $this->emitirPeloGateway($a, $clienteA, '1000.00', '1', '14');
        $antes = $this->estadoFiscal();

        // Rascunho da empresa B para um cliente da empresa A.
        $this->assertRecusado(fn () => $gateway->criarRascunho($b, $clienteA, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => '14'],
        ]));

        // Recibo da empresa B para um cliente da empresa A.
        $this->assertRecusado(fn () => $gateway->emitirRecibo($b->id, $clienteA->id, '10.00', 'dinheiro'));

        // Recibo da empresa B (cliente B) a liquidar uma fatura da empresa A.
        $this->assertRecusado(fn () => $gateway->emitirRecibo($b->id, $clienteB->id, '10.00', 'dinheiro', $faturaA->id));

        $this->assertSame($antes, $this->estadoFiscal(), 'Nenhum documento pode ter sido criado nem número consumido.');
    }

    // ---------------------------------------------------------------------
    // Preparação
    // ---------------------------------------------------------------------

    /** @return array{0: Empresa, 1: Cliente} */
    private function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif, 'nif' => null]);

        return [$empresa, $cliente];
    }

    private function emitirPeloGateway(Empresa $empresa, Cliente $cliente, string|int|float $preco, string|int $quantidade, string|int $taxa): Fatura
    {
        $gateway = app(FaturacaoGateway::class);

        return $gateway->emitirFatura($gateway->criarRascunho($empresa, $cliente, [[
            'descricao' => 'Serviço',
            'quantidade' => $quantidade,
            'preco_unitario' => $preco,
            'taxa_iva' => $taxa,
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

    /** Documentos e numeração de todas as empresas num instante. */
    private function estadoFiscal(): array
    {
        return [
            'faturas' => DB::table('faturas')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
            'recibos' => DB::table('recibos')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
            'series' => DB::table('series')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
        ];
    }
}
