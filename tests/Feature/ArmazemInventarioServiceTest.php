<?php

namespace Tests\Feature;

use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Armazem\Models\ArmazemStockMovimento;
use Modules\Armazem\Models\ArmazemStockSaldo;
use Modules\Armazem\Services\ArmazemInventarioService;
use Modules\Armazem\Services\ArmazemService;
use Modules\Armazem\Services\ArmazemStockService;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Tests\TestCase;

class ArmazemInventarioServiceTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(string $nif = '630100201'): array
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nif' => $nif,
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);
        app(TenantManager::class)->set((int) $empresa->id);

        $produtoA = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto A',
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);
        $produtoB = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto B',
            'unidade' => 'un',
            'preco_unitario' => '200.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $armazem = app(ArmazemService::class)->obterPrincipal($empresa);

        return [$empresa, $armazem, $produtoA, $produtoB];
    }

    public function test_abertura_cria_snapshot_e_registo_de_contagem(): void
    {
        [$empresa, $armazem, $produtoA, $produtoB] = $this->preparar();
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '10.000', 'Entrada inicial');

        $utilizador = User::factory()->create(['empresa_id' => $empresa->id]);
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id, $produtoB->id], $utilizador->id);
        $linhas = $inventario->linhas->keyBy('produto_id');

        $this->assertSame('10.000', (string) $linhas->get($produtoA->id)->quantidade_sistema);
        $this->assertSame('0.000', (string) $linhas->get($produtoB->id)->quantidade_sistema);

        $linha = $service->registarContagem(
            $empresa,
            $inventario,
            $linhas->get($produtoA->id)->id,
            '8.500',
            $utilizador->id,
        );
        $this->assertSame('-1.500', (string) $linha->diferenca);
        $this->assertNotNull($linha->contado_em);
        $this->assertSame($utilizador->id, $linha->contado_por);
        $this->assertSame($utilizador->id, $linha->utilizadorContagem->id);
    }

    public function test_bloqueia_movimentos_dos_produtos_durante_a_contagem(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100202');
        app(ArmazemStockService::class)->entrada($empresa, $armazem, $produtoA, '5.000', 'Entrada');
        app(ArmazemInventarioService::class)->abrir($empresa, $armazem, [$produtoA->id]);

        $this->expectException(DomainException::class);
        app(ArmazemStockService::class)->saida($empresa, $armazem, $produtoA, '1.000', 'Saída durante inventário');
    }

    public function test_conclusao_exige_aprovacao_independente_antes_de_aplicar_ajustes(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100203');
        $criador = User::factory()->create(['empresa_id' => $empresa->id]);
        $aprovador = User::factory()->create(['empresa_id' => $empresa->id]);
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '10.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id], $criador->id);
        $linha = $inventario->linhas->first();
        $service->registarContagem($empresa, $inventario, $linha->id, '7.250', $criador->id);

        $submetido = $service->concluir($empresa, $inventario, $criador->id);
        $saldoPendente = ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('armazem_id', $armazem->id)
            ->where('produto_id', $produtoA->id)
            ->value('quantidade');

        $this->assertSame('aguarda_aprovacao', $submetido->estado);
        $this->assertSame($criador->id, $submetido->submetido_por);
        $this->assertSame('10.000', (string) $saldoPendente);
        $this->assertSame(1, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());

        $concluido = $service->aprovar($empresa, $inventario, $aprovador->id);
        $service->aprovar($empresa, $inventario, $aprovador->id);
        $saldo = ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('armazem_id', $armazem->id)
            ->where('produto_id', $produtoA->id)
            ->value('quantidade');

        $this->assertSame('concluido', $concluido->estado);
        $this->assertSame($aprovador->id, $concluido->concluido_por);
        $this->assertSame('7.250', (string) $saldo);
        $this->assertSame(2, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());
        $ajuste = ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('tipo', 'ajuste')->firstOrFail();
        $this->assertSame('-2.750', (string) $ajuste->quantidade);
        $this->assertSame($aprovador->id, $ajuste->utilizador_id);
    }

    public function test_nao_conclui_se_existir_produto_sem_contagem(): void
    {
        [$empresa, $armazem, $produtoA, $produtoB] = $this->preparar('630100204');
        $service = app(ArmazemInventarioService::class);
        $utilizador = User::factory()->create(['empresa_id' => $empresa->id]);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id, $produtoB->id], $utilizador->id);
        $service->registarContagem($empresa, $inventario, $inventario->linhas->first()->id, '0.000', $utilizador->id);

        $this->expectException(DomainException::class);
        $service->concluir($empresa, $inventario, $utilizador->id);
    }

    public function test_cancelamento_liberta_movimentos_sem_aplicar_ajustes(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100205');
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '4.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id]);
        $cancelado = $service->cancelar($empresa, $inventario);

        $this->assertSame('cancelado', $cancelado->estado);
        $stock->saida($empresa, $armazem, $produtoA, '1.000', 'Saída após cancelamento');
        $this->assertSame('3.000', (string) ArmazemStockSaldo::withoutGlobalScopes()->value('quantidade'));
        $this->assertSame(2, ArmazemStockMovimento::withoutGlobalScopes()->count());
    }

    public function test_impede_duas_contagens_abertas_no_mesmo_armazem(): void
    {
        [$empresa, $armazem, $produtoA, $produtoB] = $this->preparar('630100206');
        $service = app(ArmazemInventarioService::class);
        $service->abrir($empresa, $armazem, [$produtoA->id]);

        $this->expectException(DomainException::class);
        $service->abrir($empresa, $armazem, [$produtoB->id]);
    }

    public function test_inventario_de_outra_empresa_nao_pode_ser_alterado(): void
    {
        [$empresaA, $armazemA, $produtoA] = $this->preparar('630100207');
        [$empresaB, $armazemB, $produtoB] = $this->preparar('630100208');

        // O inventário pertence à empresa A: criar o registo sob o contexto A.
        app(TenantManager::class)->set((int) $empresaA->id);
        $inventario = app(ArmazemInventarioService::class)->abrir($empresaA, $armazemA, [$produtoA->id]);

        // Simular o pedido autenticado de B antes de tentar alterar o inventário de A.
        app(TenantManager::class)->set((int) $empresaB->id);

        $this->expectException(ModelNotFoundException::class);
        app(ArmazemInventarioService::class)->registarContagem($empresaB, $inventario, $inventario->linhas->first()->id, '1.000');
    }

    public function test_utilizador_que_criou_o_ajuste_nao_pode_aprova_lo(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100209');
        $criador = User::factory()->create(['empresa_id' => $empresa->id]);
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '5.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id], $criador->id);
        $linha = $inventario->linhas->first();
        $service->registarContagem($empresa, $inventario, $linha->id, '4.000', $criador->id);
        $service->concluir($empresa, $inventario, $criador->id);

        try {
            $service->aprovar($empresa, $inventario, $criador->id);
            $this->fail('O criador não pode aprovar o próprio ajuste.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('não pode aprovar', $exception->getMessage());
        }

        $this->assertSame('aguarda_aprovacao', $inventario->fresh()->estado);
        $this->assertSame('5.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('produto_id', $produtoA->id)->value('quantidade'));
    }

    public function test_utilizador_que_registou_a_contagem_tambem_nao_pode_aprovar(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100210');
        $criador = User::factory()->create(['empresa_id' => $empresa->id]);
        $contador = User::factory()->create(['empresa_id' => $empresa->id]);
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '5.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id], $criador->id);
        $linha = $inventario->linhas->first();
        $service->registarContagem($empresa, $inventario, $linha->id, '4.000', $contador->id);
        $service->concluir($empresa, $inventario, $criador->id);

        $this->expectException(DomainException::class);
        $service->aprovar($empresa, $inventario, $contador->id);
    }

    public function test_rejeicao_regista_motivo_e_liberta_os_movimentos(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100211');
        $criador = User::factory()->create(['empresa_id' => $empresa->id]);
        $revisor = User::factory()->create(['empresa_id' => $empresa->id]);
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '5.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id], $criador->id);
        $service->registarContagem($empresa, $inventario, $inventario->linhas->first()->id, '4.000', $criador->id);
        $service->concluir($empresa, $inventario, $criador->id);

        $rejeitado = $service->rejeitar($empresa, $inventario, $revisor->id, 'Contagem precisa de nova conferência.');
        $this->assertSame('rejeitado', $rejeitado->estado);
        $this->assertSame($revisor->id, $rejeitado->rejeitado_por);
        $this->assertNotNull($rejeitado->rejeitado_em);
        $this->assertSame('Contagem precisa de nova conferência.', $rejeitado->motivo_rejeicao);
        $this->assertSame('5.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('produto_id', $produtoA->id)->value('quantidade'));

        $stock->saida($empresa, $armazem, $produtoA, '1.000', 'Saída após rejeição');
        $this->assertSame('4.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('produto_id', $produtoA->id)->value('quantidade'));
    }

    public function test_movimentos_ficam_bloqueados_enquanto_aguarda_aprovacao(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100212');
        $criador = User::factory()->create(['empresa_id' => $empresa->id]);
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '5.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id], $criador->id);
        $service->registarContagem($empresa, $inventario, $inventario->linhas->first()->id, '4.000', $criador->id);
        $service->concluir($empresa, $inventario, $criador->id);

        $this->expectException(DomainException::class);
        $stock->saida($empresa, $armazem, $produtoA, '1.000', 'Saída durante aprovação');
    }

    public function test_inventario_sem_diferencas_conclui_sem_gerar_movimento_de_ajuste(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100213');
        $criador = User::factory()->create(['empresa_id' => $empresa->id]);
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '5.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id], $criador->id);
        $service->registarContagem($empresa, $inventario, $inventario->linhas->first()->id, '5.000', $criador->id);

        $concluido = $service->concluir($empresa, $inventario, $criador->id);

        $this->assertSame('concluido', $concluido->estado);
        $this->assertSame($criador->id, $concluido->concluido_por);
        $this->assertSame(1, ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->count());
    }

    public function test_aprovacao_de_inventario_de_outra_empresa_e_bloqueada(): void
    {
        [$empresaA, $armazemA, $produtoA] = $this->preparar('630100214');
        $criadorA = User::factory()->create(['empresa_id' => $empresaA->id]);
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresaA, $armazemA, $produtoA, '5.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresaA, $armazemA, [$produtoA->id], $criadorA->id);
        $service->registarContagem($empresaA, $inventario, $inventario->linhas->first()->id, '4.000', $criadorA->id);
        $service->concluir($empresaA, $inventario, $criadorA->id);

        [$empresaB] = $this->preparar('630100215');
        $aprovadorB = User::factory()->create(['empresa_id' => $empresaB->id]);

        $this->expectException(ModelNotFoundException::class);
        $service->aprovar($empresaB, $inventario, $aprovadorB->id);
    }

    public function test_impede_novo_inventario_enquanto_aguarda_aprovacao(): void
    {
        [$empresa, $armazem, $produtoA, $produtoB] = $this->preparar('630100216');
        $criador = User::factory()->create(['empresa_id' => $empresa->id]);
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id], $criador->id);
        $service->registarContagem($empresa, $inventario, $inventario->linhas->first()->id, '1.000', $criador->id);
        $service->concluir($empresa, $inventario, $criador->id);

        $this->expectException(DomainException::class);
        $service->abrir($empresa, $armazem, [$produtoB->id], $criador->id);
    }

    public function test_contador_anterior_nao_pode_aprovar_apos_recontagem_por_outro_utilizador(): void
    {
        [$empresa, $armazem, $produtoA] = $this->preparar('630100217');
        $criador = User::factory()->create(['empresa_id' => $empresa->id]);
        $primeiroContador = User::factory()->create(['empresa_id' => $empresa->id]);
        $segundoContador = User::factory()->create(['empresa_id' => $empresa->id]);
        $stock = app(ArmazemStockService::class);
        $stock->entrada($empresa, $armazem, $produtoA, '5.000', 'Entrada');
        $service = app(ArmazemInventarioService::class);
        $inventario = $service->abrir($empresa, $armazem, [$produtoA->id], $criador->id);
        $linha = $inventario->linhas->first();

        $service->registarContagem($empresa, $inventario, $linha->id, '4.000', $primeiroContador->id);
        $service->registarContagem($empresa, $inventario, $linha->id, '3.500', $segundoContador->id);
        $service->concluir($empresa, $inventario, $criador->id);

        $this->assertDatabaseCount('armazem_inventario_contagens', 2);
        $this->assertDatabaseHas('armazem_inventario_contagens', [
            'empresa_id' => $empresa->id,
            'inventario_id' => $inventario->id,
            'linha_id' => $linha->id,
            'utilizador_id' => $primeiroContador->id,
            'quantidade_contada' => '4.000',
        ]);
        $this->assertDatabaseHas('armazem_inventario_contagens', [
            'empresa_id' => $empresa->id,
            'inventario_id' => $inventario->id,
            'linha_id' => $linha->id,
            'utilizador_id' => $segundoContador->id,
            'quantidade_contada' => '3.500',
        ]);

        try {
            $service->aprovar($empresa, $inventario, $primeiroContador->id);
            $this->fail('Um contador anterior também não pode aprovar o inventário.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('não pode aprovar', $exception->getMessage());
        }

        $this->assertSame('aguarda_aprovacao', $inventario->fresh()->estado);
        $this->assertSame('5.000', (string) ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('armazem_id', $armazem->id)
            ->where('produto_id', $produtoA->id)
            ->value('quantidade'));
    }
}
