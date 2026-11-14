<?php

namespace Tests\Feature;

use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        app(TenantManager::class)->set((int) $empresa->id);

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
        app(TenantManager::class)->set((int) $empresaA->id);

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

    public function test_rececao_parcial_credita_apenas_o_recebido_e_finaliza_com_divergencias(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100210');
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $origem, $produto, '10.000', 'Stock inicial');

        $service = app(ArmazemTransferenciaService::class);
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto, '5.000');
        $service->expedir($empresa, $transferencia);
        $linhaId = (int) $transferencia->linhas->first()->id;

        $parcial = [[
            'linha_id' => $linhaId,
            'quantidade_recebida' => '2.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
        ]];

        $service->registarRececaoParcial($empresa, $transferencia, $parcial, false, 'teste-rececao-210');
        $service->registarRececaoParcial($empresa, $transferencia, $parcial, false, 'teste-rececao-210');

        $this->assertSame('parcialmente_recebida', $transferencia->fresh()->estado);
        $this->assertSame('2.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('armazem_id', $destino->id)->where('produto_id', $produto->id)->value('quantidade'));
        $this->assertSame(3, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());

        $service->registarRececaoParcial($empresa, $transferencia, [[
            'linha_id' => $linhaId,
            'quantidade_recebida' => '1.000',
            'quantidade_em_falta' => '1.000',
            'quantidade_danificada' => '1.000',
        ]], true, 'teste-rececao-final-210');

        $linha = $transferencia->fresh()->linhas()->first();
        $this->assertSame('recebida_com_divergencia', $transferencia->fresh()->estado);
        $this->assertSame('3.000', (string) $linha->quantidade_recebida);
        $this->assertSame('1.000', (string) $linha->quantidade_em_falta);
        $this->assertSame('1.000', (string) $linha->quantidade_danificada);
        $this->assertSame('3.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('armazem_id', $destino->id)->where('produto_id', $produto->id)->value('quantidade'));
    }

    public function test_rececao_parcial_rejeita_excesso_e_finalizacao_incompleta(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100211');
        app(ArmazemStockService::class)->entrada($empresa, $origem, $produto, '5.000', 'Stock inicial');
        $service = app(ArmazemTransferenciaService::class);
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto, '2.000');
        $service->expedir($empresa, $transferencia);
        $linhaId = (int) $transferencia->linhas->first()->id;

        try {
            $service->registarRececaoParcial($empresa, $transferencia, [[
                'linha_id' => $linhaId,
                'quantidade_recebida' => '2.001',
                'quantidade_em_falta' => '0.000',
                'quantidade_danificada' => '0.000',
            ]], false, 'teste-excesso-211');
            $this->fail('Não deve aceitar quantidade superior à expedida.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('ultrapassa a quantidade expedida', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Para finalizar, todas as quantidades expedidas devem estar classificadas como recebidas, em falta ou danificadas.');
        $service->registarRececaoParcial($empresa, $transferencia, [[
            'linha_id' => $linhaId,
            'quantidade_recebida' => '1.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
        ]], true, 'teste-incompleta-211');
    }

    public function test_receber_integral_completa_o_remanescente_depois_de_uma_rececao_parcial(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100212');
        app(ArmazemStockService::class)->entrada($empresa, $origem, $produto, '5.000', 'Stock inicial');
        $service = app(ArmazemTransferenciaService::class);
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto, '2.000');
        $service->expedir($empresa, $transferencia);
        $linhaId = (int) $transferencia->linhas->first()->id;

        $service->registarRececaoParcial($empresa, $transferencia, [[
            'linha_id' => $linhaId,
            'quantidade_recebida' => '0.500',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
        ]], false, 'teste-remanescente-212');

        $service->receber($empresa, $transferencia);

        $this->assertSame('recebida', $transferencia->fresh()->estado);
        $this->assertSame('2.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('armazem_id', $destino->id)->where('produto_id', $produto->id)->value('quantidade'));
    }

    public function test_chave_de_idempotencia_nao_pode_ser_reutilizada_com_outros_dados(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100213');
        app(ArmazemStockService::class)->entrada($empresa, $origem, $produto, '5.000', 'Stock inicial');
        $service = app(ArmazemTransferenciaService::class);
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto, '2.000');
        $service->expedir($empresa, $transferencia);
        $linhaId = (int) $transferencia->linhas->first()->id;

        $service->registarRececaoParcial($empresa, $transferencia, [[
            'linha_id' => $linhaId,
            'quantidade_recebida' => '1.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
        ]], false, 'teste-chave-reutilizada-213');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A chave de idempotência já foi utilizada com outros dados.');
        $service->registarRececaoParcial($empresa, $transferencia, [[
            'linha_id' => $linhaId,
            'quantidade_recebida' => '0.500',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
        ]], false, 'teste-chave-reutilizada-213');
    }

    public function test_inativacao_fica_bloqueada_ate_finalizar_rececao_parcial(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100214');
        app(ArmazemStockService::class)->entrada($empresa, $origem, $produto, '5.000', 'Stock inicial');
        $service = app(ArmazemTransferenciaService::class);
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto, '2.000');
        $service->expedir($empresa, $transferencia);
        $linhaId = (int) $transferencia->linhas->first()->id;

        $service->registarRececaoParcial($empresa, $transferencia, [[
            'linha_id' => $linhaId,
            'quantidade_recebida' => '1.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
        ]], false, 'teste-inativacao-parcial-214');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Não é possível inativar este armazém enquanto existir uma transferência em trânsito associada.');
        app(ArmazemService::class)->alternarEstado($empresa, $destino);
    }

    public function test_nao_permite_criar_transferencia_com_produto_inativo(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100215');
        $produto->forceFill(['ativo' => false])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Um ou mais produtos não pertencem à empresa ou não controlam stock.');

        $this->criarTransferencia($empresa, $origem, $destino, $produto);
    }

    public function test_expedição_rejeita_produto_desativado_depois_da_criacao_e_faz_rollback(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100216');
        app(ArmazemStockService::class)->entrada($empresa, $origem, $produto, '5.000', 'Stock inicial');
        $transferencia = $this->criarTransferencia($empresa, $origem, $destino, $produto, '2.000');

        $produto->forceFill(['ativo' => false])->save();

        try {
            app(ArmazemTransferenciaService::class)->expedir($empresa, $transferencia);
            $this->fail('Não deve expedir um produto que foi desativado.');
        } catch (ModelNotFoundException) {
            $this->assertSame('rascunho', $transferencia->fresh()->estado);
            $this->assertSame('5.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
                ->where('armazem_id', $origem->id)
                ->where('produto_id', $produto->id)
                ->value('quantidade'));
        }
    }
}
