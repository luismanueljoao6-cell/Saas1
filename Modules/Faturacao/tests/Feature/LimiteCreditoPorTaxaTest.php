<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\ReciboService;
use Tests\TestCase;

/**
 * Notas de crédito: o valor creditado sem IVA nunca excede o da fatura, taxa a taxa
 * (a AGT valida o mesmo — regras E41/E42) e continua a interagir bem com os recibos.
 */
class LimiteCreditoPorTaxaTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        config([
            'faturacao.chave_privada_path' => $caminho,
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0, 7.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Taxas', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    /** @return array<string, mixed> */
    protected function linha(float|int $preco, int $taxa): array
    {
        return ['descricao' => "Serviço {$taxa}%", 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => $taxa];
    }

    /** Fatura com 1000 a 14% (IVA 140) e 1000 a 7% (IVA 70): total 2210. */
    protected function faturaDeDuasTaxas(): Fatura
    {
        $servico = app(FaturaService::class);

        return $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [$this->linha(1000, 14), $this->linha(1000, 7)]));
    }

    protected function rascunhoDeCredito(Fatura $fatura, array $linhas): NotaCreditoDebito
    {
        return app(NotaCreditoDebitoService::class)->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', 'Devolução', $linhas, $fatura->id);
    }

    public function test_credito_a_taxa_que_a_fatura_nao_tem_e_recusado(): void
    {
        $servico = app(FaturaService::class);
        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [$this->linha(1000, 14)]));

        // 500 a 7% cabe no total da fatura (1140), mas a fatura não tem linhas a 7%.
        $rascunho = $this->rascunhoDeCredito($fatura, [$this->linha(500, 7)]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('IVA 7.00%');

        app(NotaCreditoDebitoService::class)->emitir($rascunho);
    }

    public function test_credito_acima_da_base_de_uma_taxa_e_recusado_mesmo_cabendo_no_total(): void
    {
        $fatura = $this->faturaDeDuasTaxas();

        // 1500 a 14%: o total (1710) cabe nos 2210 da fatura, mas a base a 14% é só 1000.
        $rascunho = $this->rascunhoDeCredito($fatura, [$this->linha(1500, 14)]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('IVA 14.00%');

        app(NotaCreditoDebitoService::class)->emitir($rascunho);
    }

    public function test_creditos_parciais_cumulativos_dentro_da_base_sao_aceites_e_o_excesso_nao(): void
    {
        $fatura = $this->faturaDeDuasTaxas();
        $notas = app(NotaCreditoDebitoService::class);

        $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(600, 14), $this->linha(500, 7)]));
        $segunda = $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(400, 14), $this->linha(500, 7)]));

        $this->assertSame('emitida', $segunda->estado); // 1000 a 14% e 1000 a 7%: a base toda, nada a mais

        $terceira = $this->rascunhoDeCredito($fatura, [$this->linha(1, 14)]);

        $this->expectException(\DomainException::class);

        $notas->emitir($terceira);
    }

    public function test_recibo_antes_da_nota_de_credito_continua_a_respeitar_o_saldo(): void
    {
        $servico = app(FaturaService::class);
        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [$this->linha(1000, 14)])); // 1140
        $recibos = app(ReciboService::class);
        $notas = app(NotaCreditoDebitoService::class);

        $recibos->emitir($this->empresa->id, $this->cliente->id, '300.00', 'dinheiro', $fatura->id);
        $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(500, 14)])); // crédito de 570

        // Saldo: 1140 - 300 (recibo) - 570 (nota) = 270.
        $ultimo = $recibos->emitir($this->empresa->id, $this->cliente->id, '270.00', 'transferencia', $fatura->id);
        $this->assertSame('emitido', $ultimo->estado);

        $this->expectException(\DomainException::class);

        $recibos->emitir($this->empresa->id, $this->cliente->id, '0.01', 'dinheiro', $fatura->id);
    }

    public function test_duas_notas_parciais_reduzem_o_saldo_aceite_pelos_recibos(): void
    {
        $servico = app(FaturaService::class);
        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [$this->linha(1000, 14)])); // 1140
        $notas = app(NotaCreditoDebitoService::class);
        $recibos = app(ReciboService::class);

        $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(200, 14)])); // 228
        $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(300, 14)])); // 342

        // Saldo: 1140 - 228 - 342 = 570.
        $this->assertSame('emitido', $recibos->emitir($this->empresa->id, $this->cliente->id, '570.00', 'dinheiro', $fatura->id)->estado);

        $this->expectException(\DomainException::class);

        $recibos->emitir($this->empresa->id, $this->cliente->id, '0.01', 'dinheiro', $fatura->id);
    }
}
