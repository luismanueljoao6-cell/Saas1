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
        app(TenantManager::class)->set((int) $empresa->id);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif, 'nif' => null]);

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

    public function test_rascunho_pode_ser_editado_e_recalcula_totais(): void
    {
        [$empresa, $cliente] = $this->criarContexto('630000207');
        $service = app(OrcamentoService::class);
        $orcamento = $service->criar($empresa, $cliente, [[
            'descricao' => 'Serviço inicial',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);

        $atualizado = $service->atualizarRascunho($orcamento, $cliente, [[
            'descricao' => 'Serviço atualizado',
            'quantidade' => '2',
            'preco_unitario' => '250.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], today()->addDays(20)->toDateString(), 'Condições revistas');

        $this->assertSame('rascunho', $atualizado->estado);
        $this->assertSame('570.00', $atualizado->valor_total);
        $this->assertSame('Condições revistas', $atualizado->observacoes);
        $this->assertSame('Serviço atualizado', $atualizado->linhas[0]['descricao']);
        $this->assertSame(today()->addDays(20)->toDateString(), $atualizado->data_validade->toDateString());
    }

    public function test_orcamento_aceite_nao_pode_ser_editado(): void
    {
        [$empresa, $cliente] = $this->criarContexto('630000208');
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
        $service->atualizarRascunho($orcamento->fresh(), $cliente, [[
            'descricao' => 'Tentativa de alteração',
            'quantidade' => '1',
            'preco_unitario' => '200.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);
    }

    public function test_edicao_nao_pode_trocar_cliente_por_cliente_de_outra_empresa(): void
    {
        [$empresaA, $clienteA] = $this->criarContexto('630000209');
        [$empresaB, $clienteB] = $this->criarContexto('630000210');
        app(TenantManager::class)->set((int) $empresaA->id);
        $orcamento = app(OrcamentoService::class)->criar($empresaA, $clienteA, [[
            'descricao' => 'Serviço',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);

        $this->expectException(\DomainException::class);
        app(OrcamentoService::class)->atualizarRascunho($orcamento, $clienteB, [[
            'descricao' => 'Serviço',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);
    }

    public function test_tenant_atual_nao_pode_editar_orcamento_de_outra_empresa(): void
    {
        [$empresaA, $clienteA] = $this->criarContexto('630000215');
        app(TenantManager::class)->set((int) $empresaA->id);
        $orcamento = app(OrcamentoService::class)->criar($empresaA, $clienteA, [[
            'descricao' => 'Serviço',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);

        $this->criarContexto('630000216');

        $this->expectException(\\DomainException::class);
        $this->expectExceptionMessage('O contexto atual não corresponde à empresa do orçamento.');
        app(OrcamentoService::class)->atualizarRascunho($orcamento, $clienteA, [[
            'descricao' => 'Tentativa de edição',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);
    }

    public function test_duplicacao_cria_novo_rascunho_sem_reutilizar_fatura_ou_numero(): void
    {
        [$empresa, $cliente] = $this->criarContexto('630000211');
        $service = app(OrcamentoService::class);
        $origem = $service->criar($empresa, $cliente, [[
            'descricao' => 'Serviço para duplicar',
            'quantidade' => '2',
            'preco_unitario' => '150.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], today()->addDays(10)->toDateString(), 'Condições originais');
        $service->alterarEstado($origem, 'aceite');

        $copia = $service->duplicar($origem, $empresa);

        $this->assertNotSame($origem->id, $copia->id);
        $this->assertNotSame($origem->numero, $copia->numero);
        $this->assertSame((int) $empresa->id, (int) $copia->empresa_id);
        $this->assertSame((int) $cliente->id, (int) $copia->cliente_id);
        $this->assertSame('rascunho', $copia->estado);
        $this->assertSame('aceite', $origem->fresh()->estado, 'A duplicação não deve alterar o documento original.');
        $this->assertNull($copia->fatura_id);
        $this->assertNull($copia->data_validade);
        $this->assertSame('342.00', $copia->valor_total);
        $this->assertSame('Condições originais', $copia->observacoes);
    }

    public function test_duplicacao_nao_permite_cliente_de_outra_empresa(): void
    {
        [$empresaA, $clienteA] = $this->criarContexto('630000212');
        app(TenantManager::class)->set((int) $empresaA->id);
        $origem = app(OrcamentoService::class)->criar($empresaA, $clienteA, [[
            'descricao' => 'Serviço',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);

        [$empresaB, $clienteB] = $this->criarContexto('630000213');
        $this->assertNotSame((int) $empresaA->id, (int) $empresaB->id);
        $this->assertNotSame((int) $empresaA->id, (int) $clienteB->empresa_id);
        app(TenantManager::class)->set((int) $empresaB->id);

        $this->expectException(\DomainException::class);
        app(OrcamentoService::class)->duplicar($origem, $empresaA, $clienteB);
    }

    public function test_orcamento_com_estado_expirado_nao_pode_mudar_de_estado(): void
    {
        [$empresa, $cliente] = $this->criarContexto('630000214');
        $orcamento = app(OrcamentoService::class)->criar($empresa, $cliente, [[
            'descricao' => 'Serviço expirado',
            'quantidade' => '1',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'operation_type' => 'SG',
        ]], null, null);
        $orcamento->update(['estado' => 'expirado']);

        $this->expectException(\DomainException::class);
        app(OrcamentoService::class)->alterarEstado($orcamento->fresh(), 'aceite');
    }
}
