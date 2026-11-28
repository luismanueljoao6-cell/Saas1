<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Armazem\Services\ArmazemInventarioService;
use Modules\Armazem\Services\ArmazemService;
use Modules\Armazem\Services\ArmazemStockService;
use Modules\Armazem\Services\ArmazemTransferenciaService;
use Modules\Armazem\Support\PapeisArmazem;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ArmazemAuditoriaUtilizadorTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_paginas_de_auditoria_nao_exibem_identidades_de_outra_empresa(): void
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa Auditoria A',
            'nif' => '630200401',
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);
        app(TenantManager::class)->set((int) $empresa->id);

        $administrador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Administrador Auditoria',
            'email' => 'admin-auditoria-tenant@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        $outraEmpresa = Empresa::create([
            'nome_comercial' => 'Empresa Auditoria B',
            'nif' => '630200402',
        ]);
        $utilizadorExterno = User::create([
            'empresa_id' => $outraEmpresa->id,
            'name' => 'Nome Confidencial de Outra Empresa',
            'email' => 'externo-auditoria-tenant@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        PapeisArmazem::provisionar((int) $empresa->id);

        $registrar = app(PermissionRegistrar::class);
        $equipaAnterior = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId((int) $empresa->id);
        $papel = Role::query()
            ->where('empresa_id', $empresa->id)
            ->where('name', 'Administrador')
            ->firstOrFail();
        $administrador->assignRole($papel);
        $registrar->setPermissionsTeamId($equipaAnterior);
        $registrar->forgetCachedPermissions();

        $armazemOrigem = app(ArmazemService::class)->obterPrincipal($empresa);
        $armazemDestino = app(ArmazemService::class)->criar($empresa, 'DESTINO', 'Armazém destino');

        $produto = Produto::create([
            'empresa_id' => $empresa->id,
            'codigo' => 'AUD-001',
            'nome' => 'Produto de auditoria',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $stock = app(ArmazemStockService::class);
        $stock->entrada(
            $empresa,
            $armazemOrigem,
            $produto,
            '5.000',
            'Entrada para transferência',
            $administrador->id,
            'auditoria-tenant-entrada-0001',
        );

        $transferencias = app(ArmazemTransferenciaService::class);
        $transferencia = $transferencias->criar(
            $empresa,
            $armazemOrigem,
            $armazemDestino,
            [['produto_id' => $produto->id, 'quantidade' => '2.000']],
            'Reposição entre armazéns',
            $administrador->id,
        );
        $transferencias->expedir($empresa, $transferencia, $administrador->id);

        $linhaTransferencia = $transferencia->linhas->first();
        $this->assertNotNull($linhaTransferencia);

        $transferencias->registarRececaoParcial(
            $empresa,
            $transferencia,
            [[
                'linha_id' => $linhaTransferencia->id,
                'quantidade_recebida' => '1.000',
                'quantidade_em_falta' => '0.000',
                'quantidade_danificada' => '0.000',
            ]],
            false,
            'auditoria-tenant-rececao-0001',
            $administrador->id,
        );

        $rececao = DB::table('armazem_transferencia_rececoes')
            ->where('transferencia_id', $transferencia->id)
            ->first();
        $this->assertNotNull($rececao);
        DB::table('armazem_transferencia_rececoes')
            ->where('id', $rececao->id)
            ->update(['utilizador_id' => $utilizadorExterno->id]);

        $inventario = app(ArmazemInventarioService::class)->abrir(
            $empresa,
            $armazemOrigem,
            [$produto->id],
            $administrador->id,
        );
        $linhaInventario = $inventario->linhas->first();
        $this->assertNotNull($linhaInventario);

        // Simula registos históricos inconsistentes criados fora dos serviços de domínio.
        DB::table('armazem_inventarios')
            ->where('id', $inventario->id)
            ->update(['aberto_por' => $utilizadorExterno->id]);
        DB::table('armazem_inventario_linhas')
            ->where('id', $linhaInventario->id)
            ->update([
                'contado_por' => $utilizadorExterno->id,
                'quantidade_contada' => '1.000',
                'diferenca' => '1.000',
                'contado_em' => now(),
            ]);
        DB::table('armazem_inventario_contagens')->insert([
            'empresa_id' => $empresa->id,
            'inventario_id' => $inventario->id,
            'linha_id' => $linhaInventario->id,
            'utilizador_id' => $utilizadorExterno->id,
            'quantidade_sistema' => '0.000',
            'quantidade_contada' => '1.000',
            'diferenca' => '1.000',
            'registado_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('armazem_stock_movimentos')->insert([
            'empresa_id' => $empresa->id,
            'armazem_id' => $armazemOrigem->id,
            'produto_id' => $produto->id,
            'utilizador_id' => $utilizadorExterno->id,
            'tipo' => 'entrada',
            'quantidade' => '1.000',
            'saldo_anterior' => '0.000',
            'saldo_posterior' => '1.000',
            'motivo' => 'Movimento histórico para teste de isolamento',
            'chave_idempotencia' => 'auditoria-tenant-movimento-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($administrador);

        $this->get(route('armazem.inventarios.index'))
            ->assertOk()
            ->assertDontSee($utilizadorExterno->name);

        $this->get(route('armazem.relatorios.stock'))
            ->assertOk()
            ->assertDontSee($utilizadorExterno->name);

        $this->get(route('armazem.transferencias.index'))
            ->assertOk()
            ->assertDontSee($utilizadorExterno->name);
    }
}
