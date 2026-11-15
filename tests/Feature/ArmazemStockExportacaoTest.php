<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Armazem\Models\ArmazemStockMinimo;
use Modules\Armazem\Models\ArmazemStockSaldo;
use Modules\Armazem\Services\ArmazemService;
use Modules\Armazem\Support\PapeisArmazem;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ArmazemStockExportacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_exporta_saldos_proximos_do_limite_decimal_sem_alterar_milesimos(): void
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa Exportação Decimal',
            'nif' => '630200301',
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);
        app(TenantManager::class)->set((int) $empresa->id);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Administrador de Teste',
            'email' => 'admin-exportacao-decimal@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        PapeisArmazem::provisionar((int) $empresa->id);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $empresa->id);
        $papel = Role::query()
            ->where('empresa_id', $empresa->id)
            ->where('name', 'Administrador')
            ->firstOrFail();
        $utilizador->assignRole($papel);

        $armazem = app(ArmazemService::class)->obterPrincipal($empresa);
        $produto = Produto::create([
            'empresa_id' => $empresa->id,
            'codigo' => 'DECIMAL-001',
            'nome' => 'Produto de precisão',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        ArmazemStockSaldo::create([
            'empresa_id' => $empresa->id,
            'armazem_id' => $armazem->id,
            'produto_id' => $produto->id,
            'quantidade' => '99999999999.998',
        ]);
        ArmazemStockMinimo::create([
            'empresa_id' => $empresa->id,
            'armazem_id' => $armazem->id,
            'produto_id' => $produto->id,
            'quantidade_minima' => '99999999999.999',
        ]);

        $resposta = $this->actingAs($utilizador)
            ->get(route('armazem.relatorios.stock.exportar'));

        $resposta->assertOk()->assertDownload();
        $csv = $resposta->streamedContent();

        $this->assertStringContainsString(
            'DECIMAL-001;Produto de precisão;Armazém principal;99999999999.998;99999999999.999;Abaixo do mínimo',
            $csv,
        );
    }
}
