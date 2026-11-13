<?php

namespace Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Armazem\Models\ArmazemStockMovimento;
use Modules\Armazem\Models\ArmazemStockSaldo;
use Modules\Armazem\Services\ArmazemService;
use Modules\Armazem\Services\ArmazemStockService;
use Modules\Armazem\Services\ArmazemTransferenciaService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Tests\TestCase;

class ArmazemTransferenciaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(string $nif): array
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nif' => $nif,
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);

        $produto = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto transferível',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $armazens = app(ArmazemService::class);
        $origem = $armazens->obterPrincipal($empresa);
        $destino = $armazens->criar($empresa, 'DEST-'.$nif, 'Armazém de destino');

        return [$empresa, $origem, $destino, $produto];
    }

    private function criarTransferencia(Empresa $empresa, $origem, $destino, Produto $produto, string $quantidade = '2.500')
    {
        return app(ArmazemTransferenciaService::class)->criar(
            $empresa,
            $origem,
            $destino,
            [['produto_id' => $produto->id, 'quantidade' => $quantidade]],
            'Reposição entre armazéns'
        );
    }

    public function test_transferencia_expedida_e_recebida_move_stock_entre_armazens(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100201');
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $origem, $produto, '10.000', 'Stock inicial');

        $service = app(ArmazemTransferenciaService::class);
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto);

        $this->assertSame('rascunho', $transferencia->estado);

        $expedida = $service->expedir($empresa, $transferencia);
        $this->assertSame('em_transito', $expedida->estado);
        $this->assertSame('7.500', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('armazem_id', $origem->id)->where('produto_id', $produto->id)->value('quantidade'));

        $recebida = $service->receber($empresa, $transferencia);
        $this->assertSame('recebida', $recebida->estado);
        $this->assertSame('2.500', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('armazem_id', $destino->id)->where('produto_id', $produto->id)->value('quantidade'));
        $this->assertSame('2.500', (string) $recebida->linhas->first()->quantidade_recebida);
        $this->assertSame(3, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());
    }

    public function test_expedição_sem_stock_suficiente_nao_altera_estado_nem_saldo(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100202');
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto, '1.000');

        try {
            app(ArmazemTransferenciaService::class)->expedir($empresa, $transferencia);
            $this->fail('A expedição devia falhar por falta de stock.');
        } catch (DomainException $exception) {
            $this->assertSame('Stock insuficiente neste armazém.', $exception->getMessage());
        }

        $this->assertSame('rascunho', $transferencia->fresh()->estado);
        $this->assertSame(0, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());
    }

    public function test_expedição_e_rececao_repetidas_sao_idempotentes(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100203');
        app(ArmazemStockService::class)->entrada($empresa, $origem, $produto, '5.000', 'Stock inicial');
        $service = app(ArmazemTransferenciaService::class);
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto);

        $service->expedir($empresa, $transferencia);
        $service->expedir($empresa, $transferencia);
        $service->receber($empresa, $transferencia);
        $service->receber($empresa, $transferencia);

        $this->assertSame('2.500', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('armazem_id', $origem->id)->where('produto_id', $produto->id)->value('quantidade'));
        $this->assertSame('2.500', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('armazem_id', $destino->id)->where('produto_id', $produto->id)->value('quantidade'));
        $this->assertSame(3, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());
    }

    public function test_apenas_rascunho_pode_ser_cancelado(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100204');
        $service = app(ArmazemTransferenciaService::class);
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto);

        $cancelada = $service->cancelar($empresa, $transferencia);
        $this->assertSame('cancelada', $cancelada->estado);
        $this->assertSame('cancelada', $service->cancelar($empresa, $transferencia)->estado);

        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $origem, $produto, '5.000', 'Stock inicial');
        $segunda = $this->criarTransferencia($empresa, $origem, $destino, $produto);
        $service->expedir($empresa, $segunda);

        $this->expectException(DomainException::class);
        $service->cancelar($empresa, $segunda);
    }

    public function test_nao_permite_transferencia_entre_empresas_ou_tenant_divergente(): void
    {
        [$empresaA, $origemA, $destinoA, $produtoA] = $this->preparar('630100205');
        [$empresaB, $origemB] = $this->preparar('630100206');
        $service = app(ArmazemTransferenciaService::class);

        try {
            $service->criar(
                $empresaA,
                $origemA,
                $origemB,
                [['produto_id' => $produtoA->id, 'quantidade' => '1.000']],
                'Tentativa entre empresas'
            );
            $this->fail('Não deve aceitar armazém de outra empresa.');
        } catch (DomainException $exception) {
            $this->assertSame('Os dois armazéns devem pertencer à mesma empresa.', $exception->getMessage());
        }

        app(TenantManager::class)->set((int) $empresaA->id);
        $this->expectException(DomainException::class);
        $service->criar(
            $empresaB,
            $origemB,
            $destinoA,
            [['produto_id' => $produtoA->id, 'quantidade' => '1.000']],
            'Tenant divergente'
        );
    }

    public function test_nao_permite_mesmo_armazem_ou_quantidade_zero(): void
    {
        [$empresa, $origem, , $produto] = $this->preparar('630100207');
        $service = app(ArmazemTransferenciaService::class);

        try {
            $service->criar($empresa, $origem, $origem, [
                ['produto_id' => $produto->id, 'quantidade' => '1.000'],
            ], 'Origem igual ao destino');
            $this->fail('Não deve aceitar o mesmo armazém.');
        } catch (DomainException $exception) {
            $this->assertSame('O armazém de origem e o de destino devem ser diferentes.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $service->criar($empresa, $origem, app(ArmazemService::class)->criar($empresa, 'DEST-ZERO', 'Destino zero'), [
            ['produto_id' => $produto->id, 'quantidade' => '0.000'],
        ], 'Quantidade zero');
    }

    public function test_nao_permite_inativar_armazem_com_transferencia_em_transito(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100208');
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $origem, $produto, '5.000', 'Stock inicial');

        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto, '1.000');
        app(ArmazemTransferenciaService::class)->expedir($empresa, $transferencia);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Não é possível inativar este armazém enquanto existir uma transferência em trânsito associada.');

        app(ArmazemService::class)->alternarEstado($empresa, $destino);
    }

    public function test_permite_inativar_armazem_depois_da_rececao_da_transferencia(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100209');
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $origem, $produto, '5.000', 'Stock inicial');

        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto, '1.000');
        $service = app(ArmazemTransferenciaService::class);
        $service->expedir($empresa, $transferencia);
        $service->receber($empresa, $transferencia);

        $inativo = app(ArmazemService::class)->alternarEstado($empresa, $destino);

        $this->assertFalse($inativo->ativo);
    }
}
