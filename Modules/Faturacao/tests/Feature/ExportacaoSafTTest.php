<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\SafTExportService;
use Tests\TestCase;

class ExportacaoSafTTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reproduz exatamente o bug real: um worker de filas não tem tenant
     * "ambiente" nenhum. Sem o bypass no GerarSafTJob, esta chamada
     * devolveria um XML sem clientes nem faturas — mesmo com dados na
     * base de dados — porque a TenantScope (fail-closed) bloquearia as
     * queries de Cliente/Produto/Fatura por trás do
     * SafTExportService::gerar().
     */
    public function test_saft_inclui_dados_mesmo_sem_tenant_ambiente_definido(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa SAF-T', 'nif' => '400000001']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente SAF-T', 'nif' => null]);

        app(FaturaService::class)->emitir(
            app(FaturaService::class)->criarRascunho($empresa, $cliente, [
                ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 5000, 'taxa_iva' => 14],
            ])
        );

        // Confirma que NÃO há tenant definido neste teste (tal como num
        // worker de filas real) antes de gerar o XML.
        $this->assertFalse(app(TenantManager::class)->has());

        $xml = app(TenantManager::class)->semTenant(fn () => app(SafTExportService::class)->gerar(
            $empresa,
            now()->startOfMonth(),
            now()->endOfMonth(),
        ));

        $this->assertStringContainsString('Cliente SAF-T', $xml);
        $this->assertStringContainsString('<NumberOfEntries>1</NumberOfEntries>', $xml);
        $this->assertStringContainsString($empresa->nif, $xml);
    }
}
