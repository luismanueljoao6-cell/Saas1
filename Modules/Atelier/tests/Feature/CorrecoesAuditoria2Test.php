<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Jobs\LembretesProvaJob;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\Prova;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Services\PedidoFaturacaoService;
use Modules\Atelier\Support\NumeroTelefone;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * 2.ª varredura ao módulo Atelier: pedidos cancelados não faturam nem
 * geram lembretes, telefones angolanos, e direitos do titular dos dados.
 */
class CorrecoesAuditoria2Test extends TestCase
{
    use RefreshDatabase;

    protected function criarPedido(string $nif, array $extra = []): Pedido
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos(['atelier']);
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

    public function test_pedido_cancelado_nao_aceita_sinal(): void
    {
        $pedido = $this->criarPedido('400000301', ['status' => 'cancelado']);

        $this->expectException(PedidoException::class);

        app(PedidoFaturacaoService::class)->registarSinal($pedido, 100, 'dinheiro');
    }

    public function test_pedido_cancelado_nao_gera_fatura_final(): void
    {
        $pedido = $this->criarPedido('400000302', ['status' => 'cancelado']);

        $this->expectException(PedidoException::class);

        app(PedidoFaturacaoService::class)->gerarFaturaFinalRascunho($pedido);
    }

    public function test_lembretes_de_prova_ignoram_pedidos_cancelados(): void
    {
        $pedido = $this->criarPedido('400000303', ['status' => 'cancelado']);

        $prova = $pedido->provas()->create([
            'empresa_id' => $pedido->empresa_id,
            'tipo' => 'primeira',
            'data_hora_agendada' => now()->addHours(2),
        ]);

        (new LembretesProvaJob)->handle(app(TenantManager::class), app(NotificadorClienteService::class));

        $this->assertNull(Prova::withoutGlobalScopes()->find($prova->id)->lembrete_enviado_em);
    }

    public function test_numeros_angolanos_sao_normalizados_para_o_formato_internacional(): void
    {
        $this->assertSame('244923456789', NumeroTelefone::internacional('923 456 789'));
        $this->assertSame('244923456789', NumeroTelefone::internacional('+244 923 456 789'));
        $this->assertSame('244923456789', NumeroTelefone::internacional('00244923456789'));
        $this->assertNull(NumeroTelefone::internacional('12345'));
        $this->assertNull(NumeroTelefone::internacional(null));
        $this->assertStringEndsWith('789', NumeroTelefone::mascarar('244923456789'));
        $this->assertStringNotContainsString('923', NumeroTelefone::mascarar('244923456789'));
    }

    public function test_comando_elimina_dados_do_atelier_mas_mantem_cliente_e_pedido(): void
    {
        $pedido = $this->criarPedido('400000304');

        ClienteCrm::create([
            'cliente_id' => $pedido->cliente_id,
            'aceita_marketing' => true,
            'consentimento_em' => now(),
        ]);
        $pedido->fotos()->create(['caminho' => 'atelier/pedidos/inexistente.jpg']);

        $this->artisan('atelier:eliminar-dados-cliente', [
            'empresa' => $pedido->empresa_id,
            'cliente' => $pedido->cliente_id,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, ClienteCrm::withoutGlobalScopes()->where('cliente_id', $pedido->cliente_id)->count());
        $this->assertSame(0, $pedido->fotos()->count());
        $this->assertNotNull(Pedido::withoutGlobalScopes()->find($pedido->id));
        $this->assertNotNull(Cliente::withoutGlobalScopes()->find($pedido->cliente_id));
    }

    public function test_comando_exporta_os_dados_do_cliente(): void
    {
        $pedido = $this->criarPedido('400000305');

        $this->artisan('atelier:exportar-dados-cliente', [
            'empresa' => $pedido->empresa_id,
            'cliente' => $pedido->cliente_id,
        ])->assertExitCode(0);
    }

    public function test_comandos_recusam_cliente_de_outra_empresa(): void
    {
        $pedido = $this->criarPedido('400000306');

        $this->artisan('atelier:eliminar-dados-cliente', [
            'empresa' => $pedido->empresa_id + 999,
            'cliente' => $pedido->cliente_id,
            '--force' => true,
        ])->assertExitCode(1);
    }
}
