<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Services\PedidoFaturacaoService;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Services\FaturaService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PreparaEmpresaConcorrente;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * Pedidos do Ateliê sob concorrência real (MySQL): um pedido não recebe dois sinais, não tem duas
 * faturas finais e não recebe o saldo duas vezes. O serviço bloqueia a linha do pedido e relê-a
 * com FOR UPDATE, por isso estas guardas não dependem do nível de isolamento.
 */
#[Group('concorrencia-real')]
class PedidoFaturacaoConcorrenciaRealTest extends TestCase
{
    use PreparaEmpresaConcorrente;
    use ProcessosConcorrentes;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();

        $this->prepararEmpresaFiscal('atelier-conc');
        app(TenantManager::class)->set($this->empresa->id);
    }

    protected function novoPedido(): Pedido
    {
        return Pedido::create([
            'cliente_id' => $this->cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido de noiva',
            'valor_orcamento' => 10000,
            'taxa_iva_aplicada' => 14,
        ]);
    }

    public function test_seis_sinais_simultaneos_do_mesmo_pedido_registam_um_so(): void
    {
        $pedido = $this->novoPedido();

        $argumentos = array_fill(0, 6, ['empresa_id' => $this->empresa->id, 'pedido_id' => $pedido->id, 'valor' => '2000.00', 'meio' => 'dinheiro']);
        $resultados = $this->correrEmParalelo('atelier_sinal', $argumentos, $this->ambienteFiscal());

        $this->assertSame(1, $this->aceites($resultados), 'Um pedido só recebe um sinal.');
        $this->assertRecusasDeNegocio($resultados, 'PedidoException', 'sinal');
        $this->assertSame(1, DB::table('recibos')->where('empresa_id', $this->empresa->id)->count());
        $this->assertNotNull(DB::table((new Pedido)->getTable())->where('id', $pedido->id)->value('recibo_sinal_id'));
    }

    public function test_seis_faturas_finais_simultaneas_do_mesmo_pedido_geram_um_so_rascunho(): void
    {
        $pedido = $this->novoPedido();

        $argumentos = array_fill(0, 6, ['empresa_id' => $this->empresa->id, 'pedido_id' => $pedido->id]);
        $resultados = $this->correrEmParalelo('atelier_fatura_final', $argumentos, $this->ambienteFiscal());

        $this->assertSame(1, $this->aceites($resultados), 'Um pedido só tem uma fatura final.');
        $this->assertRecusasDeNegocio($resultados, 'PedidoException', 'fatura final');
        $this->assertSame(1, DB::table('faturas')->where('empresa_id', $this->empresa->id)->count());
    }

    public function test_seis_pagamentos_finais_simultaneos_do_mesmo_pedido_recebem_o_saldo_uma_so_vez(): void
    {
        $pedido = $this->novoPedido();
        $rascunho = app(PedidoFaturacaoService::class)->gerarFaturaFinalRascunho($pedido);
        $fatura = app(FaturaService::class)->emitir($rascunho);

        $argumentos = array_fill(0, 6, ['empresa_id' => $this->empresa->id, 'pedido_id' => $pedido->id, 'meio' => 'dinheiro']);
        $resultados = $this->correrEmParalelo('atelier_pagamento_final', $argumentos, $this->ambienteFiscal());

        $this->assertSame(1, $this->aceites($resultados), 'O saldo de um pedido só se recebe uma vez.');
        $this->assertRecusasDeNegocio($resultados, 'PedidoException', 'pagamento final');
        $this->assertSame(1, DB::table('recibos')->where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(
            number_format((float) $fatura->fresh()->valor_total, 2, '.', ''),
            number_format((float) DB::table('recibos')->where('empresa_id', $this->empresa->id)->sum('valor'), 2, '.', ''),
            'O recibo cobre exatamente o saldo da fatura.',
        );
    }
}
