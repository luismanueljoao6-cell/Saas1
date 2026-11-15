<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\OrcamentoService;
use Modules\Faturacao\Services\ReciboService;
use Tests\TestCase;

/** Regras que têm de valer no serviço, mesmo quando o chamador (outro módulo) não passa pelo FormRequest. */
class ReciboMeioPagamentoServicoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-meio-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        config(['faturacao.chave_privada_path' => $caminho, 'faturacao.agt.ativo' => false]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Meio', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    public function test_recibo_com_meio_de_pagamento_desconhecido_e_recusado_sem_consumir_numero(): void
    {
        $faturas = app(FaturaService::class);
        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço', 'quantidade' => '1', 'preco_unitario' => '100.00', 'taxa_iva' => '14'],
        ]));

        try {
            app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '50.00', 'bitcoin', $fatura->id);
            $this->fail('Um meio de pagamento fora da lista devia ser recusado.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('bitcoin', $e->getMessage());
        }

        $recibo = app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '50.00', 'multicaixa', $fatura->id);

        $this->assertSame(1, (int) $recibo->numero_sequencial);
    }

    public function test_orcamento_nao_pode_ser_criado_com_validade_no_passado(): void
    {
        $this->expectException(\DomainException::class);

        app(OrcamentoService::class)->criar($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço', 'quantidade' => '1', 'preco_unitario' => '100.00', 'taxa_iva' => '14'],
        ], now()->subDay()->toDateString(), null);
    }
}
