<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Services\PedidoFaturacaoService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\FaturaService;
use Tests\TestCase;

/**
 * Simula o duplo clique: o segundo pedido chega com um modelo carregado
 * ANTES de o primeiro ter terminado (por isso desatualizado).
 */
class FaturacaoConcorrenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $chavePrivadaPem);

        $caminho = sys_get_temp_dir().'/atelier-conc-'.uniqid().'.pem';
        file_put_contents($caminho, $chavePrivadaPem);
        config(['faturacao.chave_privada_path' => $caminho]);

        $this->beforeApplicationDestroyed(function () use ($caminho) {
            @unlink($caminho);
        });
    }

    protected function novoPedido(string $nif, float $valor = 1000): Pedido
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        app(TenantManager::class)->set($empresa->id);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif]);

        return Pedido::create([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido',
            'valor_orcamento' => $valor,
            'taxa_iva_aplicada' => 14,
        ])->fresh(); // total com IVA = 1140
    }

    protected function servico(): PedidoFaturacaoService
    {
        return app(PedidoFaturacaoService::class);
    }

    public function test_segundo_pedido_de_sinal_com_modelo_desatualizado_e_rejeitado(): void
    {
        $pedido = $this->novoPedido('500000101');
        $desatualizado = Pedido::withoutGlobalScopes()->find($pedido->id);

        $this->servico()->registarSinal($pedido, 500, 'dinheiro');

        try {
            $this->servico()->registarSinal($desatualizado, 500, 'dinheiro');
            $this->fail('O segundo sinal devia ter sido rejeitado.');
        } catch (PedidoException) {
            $this->assertSame(1, Recibo::withoutGlobalScopes()->count());
        }
    }

    public function test_sinal_acima_do_total_do_pedido_e_rejeitado(): void
    {
        $pedido = $this->novoPedido('500000102');

        $this->expectException(PedidoException::class);

        $this->servico()->registarSinal($pedido, 1141, 'dinheiro');
    }

    public function test_sinal_igual_ao_total_do_pedido_e_aceite(): void
    {
        $pedido = $this->novoPedido('500000103');

        $recibo = $this->servico()->registarSinal($pedido, 1140, 'dinheiro');

        $this->assertTrue($recibo->estaEmitido());
    }

    public function test_pagamento_do_saldo_so_pode_ser_registado_uma_vez(): void
    {
        $pedido = $this->novoPedido('500000104');
        $fatura = $this->servico()->gerarFaturaFinalRascunho($pedido);
        $fatura = app(FaturaService::class)->emitir($fatura);

        $desatualizado = Pedido::withoutGlobalScopes()->find($pedido->id);

        $recibo = $this->servico()->registarPagamentoFinal($pedido, 'dinheiro');

        $this->assertEquals((float) $fatura->valor_total, (float) $recibo->valor, 'O recibo do saldo usa o total da fatura emitida.');

        try {
            $this->servico()->registarPagamentoFinal($desatualizado, 'dinheiro');
            $this->fail('O segundo pagamento do saldo devia ter sido rejeitado.');
        } catch (PedidoException) {
            $this->assertSame(1, Recibo::withoutGlobalScopes()->count());
        }
    }

    public function test_segunda_fatura_final_com_modelo_desatualizado_e_rejeitada(): void
    {
        $pedido = $this->novoPedido('500000105');
        $desatualizado = Pedido::withoutGlobalScopes()->find($pedido->id);

        $this->servico()->gerarFaturaFinalRascunho($pedido);

        $this->expectException(PedidoException::class);

        $this->servico()->gerarFaturaFinalRascunho($desatualizado);
    }
}
