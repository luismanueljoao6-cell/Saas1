<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Support\ConfiguracaoFiscalEmpresa;
use Tests\TestCase;

class ConfiguracaoFiscalEmpresaTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferencias_fiscais_sao_isoladas_por_empresa(): void
    {
        config([
            'faturacao.taxa_iva_geral' => '14',
            'faturacao.taxas_iva_permitidas' => [14.0, 7.0, 0.0],
        ]);

        $empresaA = Empresa::create([
            'nome_comercial' => 'Empresa A',
            'nif' => '5001636863',
            'configuracoes' => [
                'faturacao' => [
                    'regime_iva' => 'simplificado',
                    'taxa_iva_padrao' => '7.00',
                    'motivos_isencao_ativos' => ['M13'],
                ],
            ],
        ]);
        $empresaB = Empresa::create([
            'nome_comercial' => 'Empresa B',
            'nif' => '5001636871',
            'configuracoes' => [
                'faturacao' => [
                    'motivos_isencao_ativos' => [],
                ],
            ],
        ]);

        $this->assertSame('7.00', ConfiguracaoFiscalEmpresa::taxaPadrao((int) $empresaA->id));
        $this->assertSame('14.00', ConfiguracaoFiscalEmpresa::taxaPadrao((int) $empresaB->id));
        $this->assertSame(['M13'], array_keys(ConfiguracaoFiscalEmpresa::motivosDisponiveis((int) $empresaA->id)));
        $this->assertNotContains('M13', array_keys(ConfiguracaoFiscalEmpresa::motivosDisponiveis((int) $empresaB->id)));
    }

    public function test_taxa_padrao_nao_permitida_regride_para_taxa_global(): void
    {
        config([
            'faturacao.taxa_iva_geral' => '14',
            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
        ]);

        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa com taxa antiga',
            'nif' => '5001636889',
            'configuracoes' => ['faturacao' => ['taxa_iva_padrao' => '7.00']],
        ]);

        $this->assertSame('14.00', ConfiguracaoFiscalEmpresa::taxaPadrao((int) $empresa->id));
    }
}
