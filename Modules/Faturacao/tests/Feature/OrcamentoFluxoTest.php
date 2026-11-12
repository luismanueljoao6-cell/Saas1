<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\OrcamentoService;
use Tests\TestCase;

class OrcamentoFluxoTest extends TestCase
{
    use RefreshDatabase;

    protected function criarContexto(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos([Servico::Faturacao]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif, 'nif' => null]);
        app(TenantManager::class)->set((int) $empresa->id);

        return [$empresa, $cliente];
    }

    public function test_orcamento_e_comercial_nao_fiscal_e_so_converte_uma_vez(): void
    {
        [$empresa, $cliente] = $this->criarContexto('630000201');
        app(TenantManager::class)->set((int) $empresa->id);

        $service = app(OrcamentoService::class);
        $orcamento = $service->criar($empresa, $cliente, [[
            'descricao' => 'Serviço contratado',
            'quantidade' => '2',
            'preco_unitario' => '1000.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], today()->addDays(15)->toDateString(), 'Proposta comercial');

        $this->assertSame('rascunho', $orcamento->estado);
        $this->assertSame('2280.00', $orcamento->valor_total);
        $this->assertNull($orcamento->fatura_id);
        $this->assertNull($orcamento->numero_documento ?? null);

        $service->alterarEstado($orcamento, 'aceite');
        $fatura = $service->converter($orcamento->fresh(), $empresa, app(FaturaService::class));

        $this->assertSame('rascunho', $fatura->estado);
        $this->assertSame('2280.00', $fatura->valor_total);
        $this->assertSame('convertido', $orcamento->fresh()->estado);
        $this->assertSame($fatura->id, $orcamento->fresh()->fatura_id);

        try {
            $service->converter($orcamento->fresh(), $empresa, app(FaturaService::class));
            $this->fail('O orçamento não pode ser convertido duas vezes.');
        } catch (\DomainException) {
            $this->assertSame('convertido', $orcamento->fresh()->estado);
        }
    }

    public function test_orcamento_nao_pode_usar_cliente_de_outra_empresa(): void
    {
        [$empresaA, $clienteA] = $this->criarContexto('630000202');
        [$empresaB] = $this->criarContexto('630000203');

        $this->expectException(\DomainException::class);
        app(OrcamentoService::class)->criar($empresaB, $clienteA, [[
            'descricao' => 'Serviço',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);
    }

    public function test_orcamento_rejeita_quantidade_de_linhas_fora_do_limite(): void
    {
        [$empresa, $cliente] = $this->criarContexto('630000204');
        $service = app(OrcamentoService::class);

        try {
            $service->criar($empresa, $cliente, [], null, null);
            $this->fail('Um orçamento vazio deve ser rejeitado.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('O orçamento deve conter entre 1 e 100 linhas.', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $service->criar($empresa, $cliente, array_fill(0, 101, []), null, null);
    }

    public function test_orcamento_recusado_nao_pode_ser_aceite_depois(): void
    {
        [$empresa, $cliente] = $this->criarContexto('630000205');
        $service = app(OrcamentoService::class);
        $orcamento = $service->criar($empresa, $cliente, [[
            'descricao' => 'Serviço',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);

        $service->alterarEstado($orcamento, 'enviado');
        $service->alterarEstado($orcamento, 'recusado');

        $this->expectException(\DomainException::class);
        $service->alterarEstado($orcamento->fresh(), 'aceite');
    }

    public function test_orcamento_aceite_nao_pode_regredir_para_enviado(): void
    {
        [$empresa, $cliente] = $this->criarContexto('630000206');
        $service = app(OrcamentoService::class);
        $orcamento = $service->criar($empresa, $cliente, [[
            'descricao' => 'Serviço',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);

        $service->alterarEstado($orcamento, 'aceite');

        $this->expectException(\DomainException::class);
        $service->alterarEstado($orcamento->fresh(), 'enviado');
    }
}
