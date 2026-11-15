<?php

namespace Modules\Faturacao\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\OrcamentoService;
use Tests\TestCase;

/** Regressões da Fase 3 da auditoria (núcleo da Faturação): guardas de tenant e orçamentos. */
class NucleoFaturacaoFase3Test extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array<string, string|int>> */
    private function linhas(string $preco = '100.00'): array
    {
        return [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => '14', 'operation_type' => 'SG']];
    }

    /** @return array{0: Empresa, 1: Cliente} com o tenant ativo definido. */
    private function contexto(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos([Servico::Faturacao]);
        app(TenantManager::class)->set((int) $empresa->id);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif, 'nif' => null]);

        return [$empresa, $cliente];
    }

    public function test_edicao_de_rascunho_recusa_empresa_fora_do_tenant_ativo(): void
    {
        [$empresa, $cliente] = $this->contexto('620000001');
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($empresa, $cliente, $this->linhas());

        $tenant = app(TenantManager::class);
        $tenant->set((int) $empresa->id + 1000);

        try {
            $servico->atualizarRascunho($rascunho, $cliente, $this->linhas('999.00'));
            $this->fail('A edição devia ser recusada fora do tenant da fatura.');
        } catch (DomainException $e) {
            $this->assertSame('A operação não pertence à empresa do contexto atual.', $e->getMessage());
        } finally {
            $tenant->clear();
        }

        $this->assertSame('114.00', (string) Fatura::withoutGlobalScopes()->findOrFail($rascunho->id)->valor_total);
    }

    public function test_eliminacao_de_rascunho_recusa_empresa_fora_do_tenant_ativo(): void
    {
        [$empresa, $cliente] = $this->contexto('620000002');
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($empresa, $cliente, $this->linhas());

        $tenant = app(TenantManager::class);
        $tenant->set((int) $empresa->id + 1000);

        try {
            $servico->eliminarRascunho($rascunho);
            $this->fail('A eliminação devia ser recusada fora do tenant da fatura.');
        } catch (DomainException $e) {
            $this->assertSame('A operação não pertence à empresa do contexto atual.', $e->getMessage());
        } finally {
            $tenant->clear();
        }

        $this->assertNotNull(Fatura::withoutGlobalScopes()->find($rascunho->id));
    }

    public function test_orcamento_nao_pode_ser_criado_com_validade_no_passado(): void
    {
        [$empresa, $cliente] = $this->contexto('620000003');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A data de validade não pode estar no passado.');

        app(OrcamentoService::class)->criar($empresa, $cliente, $this->linhas(), today()->subDay()->toDateString(), null);
    }

    public function test_orcamento_aceite_converte_sem_tenant_ambiente(): void
    {
        [$empresa, $cliente] = $this->contexto('620000004');
        $servico = app(OrcamentoService::class);
        $orcamento = $servico->criar($empresa, $cliente, $this->linhas(), today()->addDays(5)->toDateString(), null);
        $servico->alterarEstado($orcamento, 'aceite');

        // Job ou comando: não existe tenant ambiente.
        app(TenantManager::class)->clear();

        $fatura = $servico->converter($orcamento->fresh(), $empresa, app(FaturaService::class));

        $this->assertSame('rascunho', $fatura->estado);
        $this->assertSame((int) $cliente->id, (int) $fatura->cliente_id);
    }

    public function test_duplicacao_de_orcamento_funciona_sem_tenant_ambiente(): void
    {
        [$empresa, $cliente] = $this->contexto('620000005');
        $servico = app(OrcamentoService::class);
        $origem = $servico->criar($empresa, $cliente, $this->linhas(), null, null);

        app(TenantManager::class)->clear();

        $copia = $servico->duplicar($origem->fresh(), $empresa);

        $this->assertSame('rascunho', $copia->estado);
        $this->assertSame((int) $cliente->id, (int) $copia->cliente_id);
        $this->assertNotSame($origem->numero, $copia->numero);
    }
}
