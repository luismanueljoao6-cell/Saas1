<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Compra;
use Modules\Faturacao\Models\Fornecedor;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Models\StockSaldo;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CompraFornecedorTest extends TestCase
{

    use RefreshDatabase;

    protected Empresa $empresa;

    protected User $utilizador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->empresa = Empresa::create([
            'nome_comercial' => 'Empresa Compras',
            'nif' => '630000319',
        ]);
        $this->empresa->aderirServicos([Servico::Faturacao]);

        $this->utilizador = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Administrador Compras',
            'email' => 'compras@empresa.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->empresa->id);
        $papel = Role::firstOrCreate([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'empresa_id' => $this->empresa->id,
        ]);
        $this->utilizador->assignRole($papel);
        $registrar->setPermissionsTeamId(null);
    }

    public function test_regista_compra_e_atualiza_stock_uma_unica_vez_ao_receber(): void
    {
        $fornecedor = Fornecedor::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Distribuidora Angola',
            'pais' => 'AO',
            'ativo' => true,
        ]);
        $produto = Produto::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Papel A4',
            'unidade' => 'resma',
            'preco_unitario' => '2500.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
            'stock_minimo' => '2.000',
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.guardar'), [
                'fornecedor_id' => $fornecedor->id,
                'data_documento' => today()->toDateString(),
                'linhas' => [
                    0 => [
                        'selecionado' => '1',
                        'produto_id' => $produto->id,
                        'quantidade' => '4.000',
                        'preco_unitario' => '2300.00',
                        'taxa_iva' => '14',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $compra = Compra::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->firstOrFail();

        $this->assertSame('rascunho', $compra->estado);
        $this->assertSame('9200.00', (string) $compra->subtotal);
        $this->assertSame('1288.00', (string) $compra->total_iva);
        $this->assertSame('10488.00', (string) $compra->total);
        $this->assertSame('0.000', (string) ($produto->stockSaldo()->value('quantidade') ?? '0.000'));

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.receber', $compra))
            ->assertSessionHasNoErrors();

        app(TenantManager::class)->set((int) $this->empresa->id);
        $this->assertSame('4.000', (string) StockSaldo::query()->where('produto_id', $produto->id)->value('quantidade'));

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.receber', $compra))
            ->assertSessionHasErrors(['compra']);

        app(TenantManager::class)->set((int) $this->empresa->id);
        $this->assertSame('4.000', (string) StockSaldo::query()->where('produto_id', $produto->id)->value('quantidade'));

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.pagamentos.store', $compra), [
                'chave_idempotencia' => '11111111-1111-4111-8111-111111111111',
                'valor' => '5000.00',
                'data_pagamento' => today()->toDateString(),
                'metodo' => 'transferencia',
                'referencia' => 'TRF-001',
            ])
            ->assertSessionHasNoErrors();

        $compra->refresh();
        $this->assertSame('5000.00', (string) $compra->total_pago);
        $this->assertSame('parcial', $compra->estado_pagamento);

        // Repetir exatamente o mesmo pedido não pode criar um segundo pagamento.
        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.pagamentos.store', $compra), [
                'chave_idempotencia' => '11111111-1111-4111-8111-111111111111',
                'valor' => '5000.00',
                'data_pagamento' => today()->toDateString(),
                'metodo' => 'transferencia',
                'referencia' => 'TRF-001',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('compra_pagamentos', 1);
        $this->assertSame('5000.00', (string) $compra->fresh()->total_pago);

        // A mesma chave com dados diferentes é recusada sem alterar o saldo.
        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.pagamentos.store', $compra), [
                'chave_idempotencia' => '11111111-1111-4111-8111-111111111111',
                'valor' => '5001.00',
                'data_pagamento' => today()->toDateString(),
                'metodo' => 'transferencia',
                'referencia' => 'TRF-001',
            ])
            ->assertSessionHasErrors(['pagamento']);

        $this->assertDatabaseCount('compra_pagamentos', 1);
        $this->assertSame('5000.00', (string) $compra->fresh()->total_pago);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.pagamentos.store', $compra), [
                'chave_idempotencia' => '22222222-2222-4222-8222-222222222222',
                'valor' => '5488.00',
                'data_pagamento' => today()->toDateString(),
                'metodo' => 'multicaixa',
            ])
            ->assertSessionHasNoErrors();

        $compra->refresh();
        $this->assertSame('10488.00', (string) $compra->total_pago);
        $this->assertSame('pago', $compra->estado_pagamento);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.pagamentos.store', $compra), [
                'chave_idempotencia' => '33333333-3333-4333-8333-333333333333',
                'valor' => '0.01',
                'data_pagamento' => today()->toDateString(),
                'metodo' => 'dinheiro',
            ])
            ->assertSessionHasErrors(['pagamento']);
    }

    public function test_nao_permite_pagar_compra_antes_de_ser_recebida(): void
    {
        $fornecedor = Fornecedor::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Fornecedor a prazo',
            'pais' => 'AO',
            'ativo' => true,
        ]);
        $produto = Produto::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Material em rascunho',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '0',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.guardar'), [
                'fornecedor_id' => $fornecedor->id,
                'data_documento' => today()->toDateString(),
                'linhas' => [[
                    'selecionado' => '1',
                    'produto_id' => $produto->id,
                    'quantidade' => '2.000',
                    'preco_unitario' => '100.00',
                    'taxa_iva' => '0',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $compra = Compra::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->firstOrFail();

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.pagamentos.store', $compra), [
                'chave_idempotencia' => '44444444-4444-4444-8444-444444444444',
                'valor' => '50.00',
                'data_pagamento' => today()->toDateString(),
                'metodo' => 'dinheiro',
            ])
            ->assertSessionHasErrors(['pagamento']);

        $this->assertDatabaseHas('compras', [
            'id' => $compra->id,
            'estado' => 'rascunho',
            'total_pago' => '0.00',
            'estado_pagamento' => 'pendente',
        ]);
        $this->assertDatabaseCount('compra_pagamentos', 0);
    }

    public function test_nao_permite_compra_com_fornecedor_de_outra_empresa(): void
    {
        $outraEmpresa = Empresa::create([
            'nome_comercial' => 'Outra Empresa Fornecedora',
            'nif' => '630000320',
        ]);
        $fornecedorAlheio = Fornecedor::create([
            'empresa_id' => $outraEmpresa->id,
            'nome' => 'Fornecedor Externo',
            'pais' => 'AO',
            'ativo' => true,
        ]);

        $produto = Produto::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Produto válido',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.guardar'), [
                'fornecedor_id' => $fornecedorAlheio->id,
                'data_documento' => today()->toDateString(),
                'linhas' => [
                    0 => [
                        'selecionado' => '1',
                        'produto_id' => $produto->id,
                        'quantidade' => '1.000',
                        'preco_unitario' => '100.00',
                        'taxa_iva' => '0',
                    ],
                ],
            ])
            ->assertNotFound();

        $this->assertSame(0, Compra::withoutGlobalScopes()->count());
    }

    public function test_administrador_pode_editar_e_inativar_fornecedor(): void
    {
        $fornecedor = Fornecedor::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Fornecedor Antigo',
            'nif' => '500000001',
            'pais' => 'AO',
            'ativo' => true,
        ]);

        $this->actingAs($this->utilizador)
            ->put(route('faturacao.fornecedores.update', $fornecedor), [
                'nome' => 'Fornecedor Atualizado',
                'nif' => '500000002',
                'pais' => 'AO',
                'telefone' => '923000000',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fornecedores', [
            'id' => $fornecedor->id,
            'empresa_id' => $this->empresa->id,
            'nome' => 'Fornecedor Atualizado',
            'nif' => '500000002',
        ]);

        $this->actingAs($this->utilizador)
            ->patch(route('faturacao.fornecedores.estado', $fornecedor))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fornecedores', [
            'id' => $fornecedor->id,
            'ativo' => false,
        ]);
    }

    public function test_nao_permite_editar_fornecedor_de_outra_empresa(): void
    {
        $outraEmpresa = Empresa::create([
            'nome_comercial' => 'Empresa Isolada',
            'nif' => '630000321',
        ]);
        $fornecedor = Fornecedor::create([
            'empresa_id' => $outraEmpresa->id,
            'nome' => 'Fornecedor Alheio',
            'pais' => 'AO',
            'ativo' => true,
        ]);

        $this->actingAs($this->utilizador)
            ->put(route('faturacao.fornecedores.update', $fornecedor), [
                'nome' => 'Tentativa de alteração',
                'pais' => 'AO',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('fornecedores', [
            'id' => $fornecedor->id,
            'nome' => 'Fornecedor Alheio',
        ]);
    }

    public function test_nao_permite_registar_pagamento_numa_compra_de_outra_empresa(): void
    {
        $outraEmpresa = Empresa::create([
            'nome_comercial' => 'Empresa Compra Isolada',
            'nif' => '630000322',
        ]);
        $fornecedor = Fornecedor::create([
            'empresa_id' => $outraEmpresa->id,
            'nome' => 'Fornecedor da Outra Empresa',
            'pais' => 'AO',
            'ativo' => true,
        ]);
        $compra = Compra::withoutGlobalScopes()->create([
            'empresa_id' => $outraEmpresa->id,
            'fornecedor_id' => $fornecedor->id,
            'numero' => 'CMP-OUTRA-EMPRESA-001',
            'data_documento' => today()->toDateString(),
            'estado' => 'recebida',
            'subtotal' => '100.00',
            'total_iva' => '0.00',
            'total' => '100.00',
            'total_pago' => '0.00',
            'estado_pagamento' => 'pendente',
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.pagamentos.store', $compra), [
                'valor' => '50.00',
                'data_pagamento' => today()->toDateString(),
                'metodo' => 'transferencia',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('compras', [
            'id' => $compra->id,
            'empresa_id' => $outraEmpresa->id,
            'total_pago' => '0.00',
            'estado_pagamento' => 'pendente',
        ]);
        $this->assertDatabaseMissing('compra_pagamentos', [
            'compra_id' => $compra->id,
        ]);
    }

    public function test_nao_permite_receber_compra_de_outra_empresa(): void
    {
        $outraEmpresa = Empresa::create([
            'nome_comercial' => 'Empresa Receção Isolada',
            'nif' => '630000323',
        ]);
        $fornecedor = Fornecedor::create([
            'empresa_id' => $outraEmpresa->id,
            'nome' => 'Fornecedor Externo para Receção',
            'pais' => 'AO',
            'ativo' => true,
        ]);
        $produto = Produto::create([
            'empresa_id' => $outraEmpresa->id,
            'nome' => 'Produto da Empresa Externa',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '0',
            'ativo' => true,
            'controla_stock' => true,
        ]);
        $compra = Compra::withoutGlobalScopes()->create([
            'empresa_id' => $outraEmpresa->id,
            'fornecedor_id' => $fornecedor->id,
            'numero' => 'CMP-OUTRA-EMPRESA-002',
            'data_documento' => today()->toDateString(),
            'estado' => 'rascunho',
            'subtotal' => '200.00',
            'total_iva' => '0.00',
            'total' => '200.00',
            'total_pago' => '0.00',
            'estado_pagamento' => 'pendente',
        ]);
        $compra->linhas()->create([
            'empresa_id' => $outraEmpresa->id,
            'produto_id' => $produto->id,
            'descricao' => $produto->nome,
            'quantidade' => '2.000',
            'preco_unitario' => '100.00',
            'taxa_iva' => '0.00',
            'subtotal' => '200.00',
            'valor_iva' => '0.00',
            'total' => '200.00',
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.receber', $compra))
            ->assertNotFound();

        $this->assertDatabaseHas('compras', [
            'id' => $compra->id,
            'empresa_id' => $outraEmpresa->id,
            'estado' => 'rascunho',
        ]);
        $this->assertDatabaseMissing('stock_saldos', [
            'empresa_id' => $outraEmpresa->id,
            'produto_id' => $produto->id,
        ]);
        $this->assertDatabaseMissing('stock_movimentos', [
            'empresa_id' => $outraEmpresa->id,
            'produto_id' => $produto->id,
        ]);
    }

    public function test_pode_cancelar_rascunho_sem_alterar_stock(): void
    {
        $fornecedor = Fornecedor::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Fornecedor para cancelamento',
            'pais' => 'AO',
            'ativo' => true,
        ]);
        $produto = Produto::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Produto não recebido',
            'unidade' => 'un',
            'preco_unitario' => '150.00',
            'taxa_iva' => '0',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.guardar'), [
                'fornecedor_id' => $fornecedor->id,
                'data_documento' => today()->toDateString(),
                'linhas' => [[
                    'selecionado' => '1',
                    'produto_id' => $produto->id,
                    'quantidade' => '3.000',
                    'preco_unitario' => '150.00',
                    'taxa_iva' => '0',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $compra = Compra::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->firstOrFail();

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.cancelar', $compra))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sucesso', 'Compra cancelada. O stock não foi alterado.');

        $this->assertDatabaseHas('compras', [
            'id' => $compra->id,
            'empresa_id' => $this->empresa->id,
            'estado' => 'cancelada',
        ]);
        $this->assertDatabaseMissing('stock_movimentos', [
            'empresa_id' => $this->empresa->id,
            'produto_id' => $produto->id,
        ]);
        $this->assertDatabaseMissing('stock_saldos', [
            'empresa_id' => $this->empresa->id,
            'produto_id' => $produto->id,
        ]);
    }

    public function test_nao_permite_cancelar_compra_ja_recebida(): void
    {
        $fornecedor = Fornecedor::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Fornecedor compra recebida',
            'pais' => 'AO',
            'ativo' => true,
        ]);
        $produto = Produto::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Produto recebido',
            'unidade' => 'un',
            'preco_unitario' => '200.00',
            'taxa_iva' => '0',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.guardar'), [
                'fornecedor_id' => $fornecedor->id,
                'data_documento' => today()->toDateString(),
                'linhas' => [[
                    'selecionado' => '1',
                    'produto_id' => $produto->id,
                    'quantidade' => '2.000',
                    'preco_unitario' => '200.00',
                    'taxa_iva' => '0',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $compra = Compra::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->firstOrFail();

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.receber', $compra))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->utilizador)
            ->post(route('faturacao.compras.cancelar', $compra))
            ->assertSessionHasErrors(['compra']);

        $this->assertDatabaseHas('compras', [
            'id' => $compra->id,
            'estado' => 'recebida',
        ]);
        $this->assertDatabaseHas('stock_saldos', [
            'empresa_id' => $this->empresa->id,
            'produto_id' => $produto->id,
            'quantidade' => '2.000',
        ]);
    }
}
