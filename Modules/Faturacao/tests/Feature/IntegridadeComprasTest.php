<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Fornecedor;
use Modules\Faturacao\Models\Produto;
use Tests\TestCase;

class IntegridadeComprasTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresaA;

    protected Empresa $empresaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresaA = Empresa::create(['nome_comercial' => 'Compras A', 'nif' => '630000801']);
        $this->empresaB = Empresa::create(['nome_comercial' => 'Compras B', 'nif' => '630000802']);
    }

    protected function fornecedor(Empresa $empresa): Fornecedor
    {
        return Fornecedor::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Fornecedor '.$empresa->id,
            'pais' => 'AO',
            'ativo' => true,
        ]);
    }

    protected function produto(Empresa $empresa): Produto
    {
        return Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto '.$empresa->id,
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);
    }

    protected function compra(Empresa $empresa, Fornecedor $fornecedor, string $numero = 'CMP-TESTE'): int
    {
        return (int) DB::table('compras')->insertGetId([
            'empresa_id' => $empresa->id,
            'fornecedor_id' => $fornecedor->id,
            'numero' => $numero,
            'data_documento' => today()->toDateString(),
            'estado' => 'recebida',
            'subtotal' => '100.00',
            'total_iva' => '14.00',
            'total' => '114.00',
            'total_pago' => '0.00',
            'estado_pagamento' => 'pendente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function assertViolacao(callable $operacao): void
    {
        try {
            $operacao();
        } catch (QueryException) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('A base de dados devia impedir a associação ou eliminação.');
    }


    protected function linhaCompra(int $empresaId, int $compraId, Produto $produto): int
    {
        return (int) DB::table('compra_linhas')->insertGetId([
            'empresa_id' => $empresaId,
            'compra_id' => $compraId,
            'produto_id' => $produto->id,
            'descricao' => $produto->nome,
            'quantidade' => '1.000',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14.00',
            'subtotal' => '100.00',
            'valor_iva' => '14.00',
            'total' => '114.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_compra_nao_pode_referenciar_fornecedor_de_outra_empresa(): void
    {
        $fornecedorB = $this->fornecedor($this->empresaB);

        $this->assertViolacao(fn () => $this->compra($this->empresaA, $fornecedorB));

        $this->assertSame(0, DB::table('compras')->count());
    }

    public function test_linha_nao_pode_referenciar_produto_de_outra_empresa(): void
    {
        $fornecedorA = $this->fornecedor($this->empresaA);
        $produtoB = $this->produto($this->empresaB);
        $compraId = $this->compra($this->empresaA, $fornecedorA);

        $this->assertViolacao(fn () => DB::table('compra_linhas')->insert([
            'empresa_id' => $this->empresaA->id,
            'compra_id' => $compraId,
            'produto_id' => $produtoB->id,
            'descricao' => $produtoB->nome,
            'quantidade' => '1.000',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14.00',
            'subtotal' => '100.00',
            'valor_iva' => '14.00',
            'total' => '114.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertSame(0, DB::table('compra_linhas')->count());
    }

    public function test_pagamento_nao_pode_ser_associado_a_compra_de_outra_empresa(): void
    {
        $fornecedorB = $this->fornecedor($this->empresaB);
        $compraB = $this->compra($this->empresaB, $fornecedorB, 'CMP-B-001');

        $this->assertViolacao(fn () => DB::table('compra_pagamentos')->insert([
            'empresa_id' => $this->empresaA->id,
            'compra_id' => $compraB,
            'valor' => '10.00',
            'data_pagamento' => today()->toDateString(),
            'metodo' => 'transferencia',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertSame(0, DB::table('compra_pagamentos')->count());
    }

    public function test_compra_com_pagamentos_nao_pode_ser_apagada_em_cascata(): void
    {
        $fornecedorA = $this->fornecedor($this->empresaA);
        $compraA = $this->compra($this->empresaA, $fornecedorA, 'CMP-A-PAGO');

        DB::table('compra_pagamentos')->insert([
            'empresa_id' => $this->empresaA->id,
            'compra_id' => $compraA,
            'valor' => '10.00',
            'data_pagamento' => today()->toDateString(),
            'metodo' => 'transferencia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertViolacao(fn () => DB::table('compras')->where('id', $compraA)->delete());

        $this->assertSame(1, DB::table('compras')->where('id', $compraA)->count());
        $this->assertSame(1, DB::table('compra_pagamentos')->where('compra_id', $compraA)->count());
    }

    public function test_empresa_com_compras_nao_pode_ser_eliminada_em_cascata(): void
    {
        $fornecedorA = $this->fornecedor($this->empresaA);
        $this->compra($this->empresaA, $fornecedorA, 'CMP-A-RETIDA');

        $this->assertViolacao(fn () => DB::table('empresas')->where('id', $this->empresaA->id)->delete());

        $this->assertSame(1, DB::table('compras')->where('empresa_id', $this->empresaA->id)->count());
    }

    public function test_fornecedor_com_compra_historica_nao_pode_ser_eliminado(): void
    {
        $fornecedor = $this->fornecedor($this->empresaA);
        $compraId = $this->compra($this->empresaA, $fornecedor, 'CMP-FORNECEDOR-RETIDO');

        $this->assertViolacao(fn () => DB::table('fornecedores')->where('id', $fornecedor->id)->delete());

        $this->assertSame(1, DB::table('compras')->where('id', $compraId)->count());
        $this->assertSame(1, DB::table('fornecedores')->where('id', $fornecedor->id)->count());
    }

    public function test_produto_com_linha_de_compra_historica_nao_pode_ser_eliminado(): void
    {
        $fornecedor = $this->fornecedor($this->empresaA);
        $produto = $this->produto($this->empresaA);
        $compraId = $this->compra($this->empresaA, $fornecedor, 'CMP-PRODUTO-RETIDO');
        $linhaId = $this->linhaCompra($this->empresaA->id, $compraId, $produto);

        $this->assertViolacao(fn () => DB::table('produtos')->where('id', $produto->id)->delete());

        $this->assertSame(1, DB::table('compra_linhas')->where('id', $linhaId)->count());
        $this->assertSame(1, DB::table('produtos')->where('id', $produto->id)->count());
    }

    public function test_compra_com_linhas_historicas_nao_pode_ser_eliminada(): void
    {
        $fornecedor = $this->fornecedor($this->empresaA);
        $produto = $this->produto($this->empresaA);
        $compraId = $this->compra($this->empresaA, $fornecedor, 'CMP-LINHAS-RETIDAS');
        $linhaId = $this->linhaCompra($this->empresaA->id, $compraId, $produto);

        $this->assertViolacao(fn () => DB::table('compras')->where('id', $compraId)->delete());

        $this->assertSame(1, DB::table('compras')->where('id', $compraId)->count());
        $this->assertSame(1, DB::table('compra_linhas')->where('id', $linhaId)->count());
    }


}
