<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Services\MedidaService;
use Modules\Atelier\Services\PedidoStatusService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Cobre o comportamento operacional central do módulo: a máquina de estados
 * do pedido, o histórico de medidas e o isolamento por empresa das tabelas
 * novas. Assume, tal como IsolamentoTenantTest no Core, o Tests\TestCase
 * padrão e uma base de dados de teste configurada.
 *
 * Cada teste define o tenant à mão (TenantManager::set) porque, fora de um
 * pedido HTTP, não há o middleware IdentificarTenant a fazê-lo — e a
 * TenantScope é fail-closed sem tenant definido.
 */
class PedidoFluxoTest extends TestCase
{
    use RefreshDatabase;

    protected function criarPedido(string $nif = '400000001', array $extra = []): Pedido
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        app(TenantManager::class)->set($empresa->id);

        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif]);

        return Pedido::create(array_merge([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido de teste',
            'valor_orcamento' => 10000,
            'taxa_iva_aplicada' => 14,
        ], $extra));
    }

    public function test_pedido_novo_comeca_como_pendente(): void
    {
        $pedido = $this->criarPedido();

        $this->assertSame('pendente', $pedido->fresh()->status);
    }

    public function test_fluxo_completo_de_estados_ate_a_entrega(): void
    {
        Event::fake([PedidoMudouEstado::class]);
        $pedido = $this->criarPedido('400000002');
        $servico = app(PedidoStatusService::class);

        foreach (['em_corte', 'em_costura', 'primeira_prova', 'ajustes', 'pronto_para_retirada', 'entregue'] as $estado) {
            $servico->avancarPara($pedido, $estado);
        }

        $this->assertSame('entregue', $pedido->fresh()->status);
        $this->assertTrue($pedido->fresh()->estaFinalizado());
    }

    public function test_ajustes_pode_voltar_a_uma_nova_prova(): void
    {
        Event::fake([PedidoMudouEstado::class]);
        $pedido = $this->criarPedido('400000003', ['status' => 'ajustes']);

        app(PedidoStatusService::class)->avancarPara($pedido, 'primeira_prova');

        $this->assertSame('primeira_prova', $pedido->fresh()->status);
    }

    public function test_salto_de_estado_invalido_e_rejeitado(): void
    {
        $pedido = $this->criarPedido('400000004');

        $this->expectException(PedidoException::class);

        app(PedidoStatusService::class)->avancarPara($pedido, 'entregue');
    }

    public function test_pedido_entregue_ou_cancelado_nao_avanca_para_lado_nenhum(): void
    {
        Event::fake([PedidoMudouEstado::class]);
        $servico = app(PedidoStatusService::class);

        $cancelado = $this->criarPedido('400000005');
        $servico->avancarPara($cancelado, 'cancelado', 'Cliente desistiu');

        $this->assertSame('Cliente desistiu', $cancelado->fresh()->motivo_cancelamento);

        $this->expectException(PedidoException::class);
        $servico->avancarPara($cancelado, 'em_corte');
    }

    public function test_mudanca_de_estado_dispara_o_evento_que_aciona_as_notificacoes(): void
    {
        Event::fake([PedidoMudouEstado::class]);
        $pedido = $this->criarPedido('400000006');

        app(PedidoStatusService::class)->avancarPara($pedido, 'em_corte');

        Event::assertDispatched(
            PedidoMudouEstado::class,
            fn (PedidoMudouEstado $evento) => $evento->pedido->is($pedido) && $evento->estadoAnterior === 'pendente'
        );
    }

    public function test_transicao_invalida_nao_dispara_evento_nem_altera_o_estado(): void
    {
        Event::fake([PedidoMudouEstado::class]);
        $pedido = $this->criarPedido('400000007');

        try {
            app(PedidoStatusService::class)->avancarPara($pedido, 'entregue');
        } catch (PedidoException) {
            // esperado
        }

        Event::assertNotDispatched(PedidoMudouEstado::class);
        $this->assertSame('pendente', $pedido->fresh()->status);
    }

    public function test_novas_medidas_criam_um_registo_novo_e_preservam_o_historico(): void
    {
        $pedido = $this->criarPedido('400000008');
        $cliente = $pedido->cliente;
        $servico = app(MedidaService::class);

        $servico->registar($pedido->empresa, $cliente, ['busto' => 90, 'cintura' => 70], 'Primeira ficha', null);
        $servico->registar($pedido->empresa, $cliente, ['busto' => 92, 'cintura' => 71], 'Depois de perder peso', null);

        $historico = $servico->historicoPara($cliente);

        $this->assertCount(2, $historico);
        $this->assertEquals(92, $servico->maisRecentePara($cliente)->busto);
        $this->assertEquals(90, $historico->last()->busto, 'A ficha antiga nunca é substituída.');
    }

    public function test_medidas_ignoram_campos_que_nao_sao_de_medida(): void
    {
        $pedido = $this->criarPedido('400000009');

        $medida = app(MedidaService::class)->registar(
            $pedido->empresa,
            $pedido->cliente,
            ['busto' => 88, 'empresa_id' => 9999, 'campo_inventado' => 'x'],
            null,
            null,
        );

        $this->assertSame($pedido->empresa_id, $medida->empresa_id);
        $this->assertEquals(88, $medida->busto);
    }

    public function test_uma_empresa_nunca_ve_pedidos_de_outra(): void
    {
        $pedidoA = $this->criarPedido('400000010');
        $empresaA = $pedidoA->empresa;

        $pedidoB = $this->criarPedido('400000011'); // muda o tenant atual para a empresa B
        $empresaB = $pedidoB->empresa;

        $tenantManager = app(TenantManager::class);

        $tenantManager->set($empresaA->id);
        $this->assertEquals([$pedidoA->id], Pedido::pluck('id')->all());

        $tenantManager->set($empresaB->id);
        $this->assertEquals([$pedidoB->id], Pedido::pluck('id')->all());

        $tenantManager->clear();
        $this->assertCount(0, Pedido::all(), 'Sem tenant definido a query tem de ficar vazia (fail-closed).');
    }
}
