<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoMaterial;
use Modules\Atelier\Services\PedidoFaturacaoService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Exceptions\DocumentoImutavelException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\FaturaService;
use Tests\TestCase;

/**
 * Prova a ligação entre o Atelier e o motor fiscal do Faturacao (requisito
 * B): sinal → Recibo sem fatura, entrega → rascunho de Fatura, saldo →
 * Recibo ligado à fatura. Corre o motor fiscal REAL (numeração, hash RSA),
 * tal como CadeiaFiscalTest no módulo Faturacao — por isso gera aqui uma
 * chave RSA descartável, com o mesmo procedimento.
 */
class FaturacaoIntegradaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $chavePrivadaPem);

        $caminho = sys_get_temp_dir().'/atelier-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $chavePrivadaPem);

        config(['faturacao.chave_privada_path' => $caminho]);

        $this->beforeApplicationDestroyed(function () use ($caminho) {
            @unlink($caminho);
        });
    }

    /** @return array{0: Empresa, 1: Cliente} */
    protected function criarEmpresaComCliente(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        app(TenantManager::class)->set($empresa->id);

        return [$empresa, Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif])];
    }

    protected function novoPedido(Cliente $cliente, float $valorOrcamento = 10000, array $materiais = []): Pedido
    {
        $pedido = Pedido::create([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido de noiva',
            'valor_orcamento' => $valorOrcamento,
            'taxa_iva_aplicada' => 14,
        ]);

        foreach ($materiais as $indice => $dados) {
            (new PedidoMaterial([
                'pedido_id' => $pedido->id,
                'descricao' => $dados['descricao'],
                'quantidade' => $dados['quantidade'],
                'valor_unitario' => $dados['valor_unitario'],
                'ordem' => $indice,
            ]))->calcularValores()->save();
        }

        return $pedido->fresh();
    }

    protected function servico(): PedidoFaturacaoService
    {
        return app(PedidoFaturacaoService::class);
    }

    public function test_sinal_gera_recibo_emitido_e_assinado_sem_fatura_associada(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000001');
        $pedido = $this->novoPedido($cliente);

        $recibo = $this->servico()->registarSinal($pedido, 3000, 'dinheiro');

        $this->assertTrue($recibo->estaEmitido());
        $this->assertNull($recibo->fatura_id, 'Um sinal é um adiantamento — ainda não há fatura a que ligar.');
        $this->assertNotNull($recibo->numero_documento);
        $this->assertNotNull($recibo->hash);
        $this->assertEquals(3000, $recibo->valor);

        $pedido = $pedido->fresh();
        $this->assertEquals(3000, $pedido->valor_sinal);
        $this->assertSame($recibo->id, $pedido->recibo_sinal_id);
    }

    public function test_nao_e_possivel_registar_dois_sinais_no_mesmo_pedido(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000002');
        $pedido = $this->novoPedido($cliente);

        $this->servico()->registarSinal($pedido, 1000, 'dinheiro');

        $this->expectException(PedidoException::class);

        $this->servico()->registarSinal($pedido->fresh(), 1000, 'dinheiro');
    }

    public function test_sinal_com_valor_zero_e_rejeitado(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000003');
        $pedido = $this->novoPedido($cliente);

        $this->expectException(PedidoException::class);

        $this->servico()->registarSinal($pedido, 0, 'dinheiro');
    }

    public function test_recibos_do_atelier_entram_na_cadeia_de_numeracao_e_hash_dos_recibos(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000004');

        $primeiro = $this->servico()->registarSinal($this->novoPedido($cliente), 1000, 'dinheiro');
        $segundo = $this->servico()->registarSinal($this->novoPedido($cliente), 2000, 'multicaixa');

        $this->assertSame(1, $primeiro->numero_sequencial);
        $this->assertSame(2, $segundo->numero_sequencial);
        $this->assertNull($primeiro->hash_anterior);
        $this->assertSame($primeiro->hash, $segundo->hash_anterior);
    }

    public function test_recibo_emitido_pelo_atelier_e_imutavel(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000005');
        $recibo = $this->servico()->registarSinal($this->novoPedido($cliente), 1000, 'dinheiro');

        $this->expectException(DocumentoImutavelException::class);

        $recibo->update(['valor' => 1]);
    }

    public function test_fatura_final_e_um_rascunho_com_uma_linha_para_a_peca_e_uma_por_material(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000006');
        $pedido = $this->novoPedido($cliente, 10000, [
            ['descricao' => 'Fecho de correr', 'quantidade' => 2, 'valor_unitario' => 500],
        ]);

        $fatura = $this->servico()->gerarFaturaFinalRascunho($pedido);

        $this->assertSame('rascunho', $fatura->estado);
        $this->assertCount(2, $fatura->linhas);
        // (10000 + 2×500) = 11000 sem IVA; IVA a 14% = 1540; total = 12540.
        $this->assertEquals(11000, $fatura->valor_sem_iva);
        $this->assertEquals(1540, $fatura->valor_iva);
        $this->assertEquals(12540, $fatura->valor_total);
        $this->assertSame($fatura->id, $pedido->fresh()->fatura_id);
    }

    public function test_o_total_do_pedido_coincide_com_o_total_da_fatura_gerada(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000007');
        $pedido = $this->novoPedido($cliente, 8000, [
            ['descricao' => 'Linha', 'quantidade' => 3, 'valor_unitario' => 150],
            ['descricao' => 'Botões', 'quantidade' => 10, 'valor_unitario' => 25],
        ]);

        $fatura = $this->servico()->gerarFaturaFinalRascunho($pedido);

        $this->assertEquals($pedido->valorTotalComIva(), $fatura->valor_total);
    }

    public function test_nao_e_possivel_gerar_duas_faturas_finais_para_o_mesmo_pedido(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000008');
        $pedido = $this->novoPedido($cliente);

        $this->servico()->gerarFaturaFinalRascunho($pedido);

        $this->expectException(PedidoException::class);

        $this->servico()->gerarFaturaFinalRascunho($pedido->fresh());
    }

    public function test_pagamento_final_exige_uma_fatura_final(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000009');
        $pedido = $this->novoPedido($cliente);

        $this->expectException(PedidoException::class);

        $this->servico()->registarPagamentoFinal($pedido, 'dinheiro');
    }

    public function test_pagamento_final_exige_que_a_fatura_ja_tenha_sido_emitida(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000010');
        $pedido = $this->novoPedido($cliente);
        $this->servico()->gerarFaturaFinalRascunho($pedido); // fica em rascunho

        $this->expectException(PedidoException::class);

        $this->servico()->registarPagamentoFinal($pedido->fresh(), 'dinheiro');
    }

    public function test_pagamento_final_emite_recibo_com_o_saldo_e_ligado_a_fatura(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000011');
        $pedido = $this->novoPedido($cliente, 10000, [
            ['descricao' => 'Fecho de correr', 'quantidade' => 2, 'valor_unitario' => 500],
        ]);

        $reciboSinal = $this->servico()->registarSinal($pedido, 5000, 'transferencia');
        $fatura = $this->servico()->gerarFaturaFinalRascunho($pedido);
        app(FaturaService::class)->emitir($fatura);

        // Total 12540 − sinal 5000 = 7540 em falta.
        $reciboSaldo = $this->servico()->registarPagamentoFinal($pedido, 'multicaixa');

        $this->assertTrue($reciboSaldo->estaEmitido());
        $this->assertEquals(7540, $reciboSaldo->valor);
        $this->assertSame($fatura->id, $reciboSaldo->fatura_id);
        $this->assertSame($reciboSaldo->id, $pedido->fresh()->recibo_saldo_final_id);

        // Os dois recibos partilham a mesma cadeia: o do saldo encadeia com o do sinal.
        $this->assertSame(2, $reciboSaldo->numero_sequencial);
        $this->assertSame($reciboSinal->hash, $reciboSaldo->hash_anterior);
    }

    public function test_pagamento_final_sem_saldo_em_falta_e_rejeitado(): void
    {
        [, $cliente] = $this->criarEmpresaComCliente('500000012');
        $pedido = $this->novoPedido($cliente, 1000); // total com IVA = 1140

        $this->servico()->registarSinal($pedido, 1140, 'dinheiro');
        $fatura = $this->servico()->gerarFaturaFinalRascunho($pedido);
        app(FaturaService::class)->emitir($fatura);

        $this->expectException(PedidoException::class);

        $this->servico()->registarPagamentoFinal($pedido, 'dinheiro');
    }
}
