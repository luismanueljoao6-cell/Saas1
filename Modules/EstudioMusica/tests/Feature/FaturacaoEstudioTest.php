<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\CupomDesconto;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Services\FaturacaoEstudioService;
use Modules\EstudioMusica\Tests\Support\CriaDocumentosFiscais;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\ReciboService;
use Tests\TestCase;

require_once __DIR__.'/../Support/CriaDocumentosFiscais.php';

/**
 * Faturação do Estúdio contra o motor REAL do Faturação (emissão, notas de
 * crédito e recibos): o que é faturado, creditado e recebido tem de bater
 * certo com o que o projeto considera "devido" e "quitado".
 */
class FaturacaoEstudioTest extends TestCase
{
    use CriaDocumentosFiscais;
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected FaturacaoEstudioService $faturacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararChaveFiscal();
        config(['faturacao.taxa_iva_geral' => 14.0]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Faturação', 'nif' => '400000031']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Artista Faturação']);
        $this->faturacao = app(FaturacaoEstudioService::class);
    }

    protected function projeto(string $tipo = 'pacote', array $extra = []): ProjetoMusical
    {
        return ProjetoMusical::create(array_merge([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'nome' => 'Projeto Faturação',
            'tipo_cobranca' => $tipo,
            'valor_pacote' => $tipo === 'pacote' ? 100000 : null,
            'percentual_sinal' => 40,
        ], $extra));
    }

    protected function anularComNotaDeCredito($fatura): void
    {
        $notas = app(NotaCreditoDebitoService::class);
        $notas->emitir($notas->criarParaAnularFatura($fatura, 'Emitida por engano'));
    }

    protected function sessaoConcluida(int $minutos, ?ProjetoMusical $projeto = null, float $precoHora = 5000): SessaoEstudio
    {
        $sala = SalaEstudio::create(['empresa_id' => $this->empresa->id, 'nome' => 'Sala A', 'preco_hora' => $precoHora]);
        $inicio = Carbon::parse('2026-10-20 10:00:00');

        $sessao = new SessaoEstudio;
        $sessao->forceFill([
            'empresa_id' => $this->empresa->id,
            'sala_estudio_id' => $sala->id,
            'cliente_id' => $this->cliente->id,
            'projeto_musical_id' => $projeto?->id,
            'tipo_servico' => 'mixagem',
            'inicio_previsto' => $inicio,
            'fim_previsto' => $inicio->copy()->addMinutes($minutos),
            'inicio_real' => $inicio,
            'fim_real' => $inicio->copy()->addMinutes($minutos),
            'estado' => 'concluida',
        ])->save();

        return $sessao;
    }

    public function test_sinal_e_saldo_somam_exatamente_o_pacote_mais_iva(): void
    {
        $projeto = $this->projeto();

        $sinal = $this->faturacao->gerarFaturaSinal($projeto);
        $saldo = $this->faturacao->gerarFaturaSaldoFinal($projeto);

        $this->assertSame('45600.00', $sinal->valor_total);
        $this->assertSame('68400.00', $saldo->valor_total);
        $this->assertSame(114000.0, $projeto->valorDevido());
    }

    public function test_anular_o_sinal_com_nota_de_credito_permite_emiti_lo_outra_vez(): void
    {
        $projeto = $this->projeto();
        $sinal = $this->faturacao->gerarFaturaSinal($projeto);

        $this->anularComNotaDeCredito($sinal);

        // O sinal anulado já não conta como emitido: nem para o bloqueio "já foi emitido",
        // nem para o que está faturado — senão o projeto ficava preso para sempre.
        $this->assertSame(0.0, $projeto->valorTotalFaturado());
        $this->assertSame(0.0, $projeto->valorTotalFaturadoSemIva());

        $novo = $this->faturacao->gerarFaturaSinal($projeto);

        $this->assertNotSame($sinal->id, $novo->id);
        $this->assertSame('45600.00', $novo->valor_total);
        $this->assertSame(45600.0, $projeto->valorTotalFaturado());
    }

    public function test_anular_o_saldo_permite_refaturar_o_que_falta(): void
    {
        $projeto = $this->projeto();
        $this->faturacao->gerarFaturaSinal($projeto);
        $saldo = $this->faturacao->gerarFaturaSaldoFinal($projeto);

        $this->anularComNotaDeCredito($saldo);

        $novoSaldo = $this->faturacao->gerarFaturaSaldoFinal($projeto);

        $this->assertSame('68400.00', $novoSaldo->valor_total);
    }

    public function test_fatura_anulada_nao_deixa_o_projeto_por_hora_com_divida_fantasma(): void
    {
        $projeto = $this->projeto('hora');
        $fatura = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 30000);
        $projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);

        $this->notaCreditoEmitida($fatura, 30000);

        $this->assertSame(0.0, $projeto->valorDevido());
        $this->assertSame(0.0, $projeto->saldoDevedor());
        $this->assertFalse($projeto->financeiramenteQuitado(), 'Sem nada devido não há o que quitar.');
    }

    public function test_desconto_por_nota_de_credito_reduz_o_que_e_preciso_pagar_para_quitar(): void
    {
        $projeto = $this->projeto();
        $sinal = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 45600, 40000);
        $saldo = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 68400, 60000);
        $projeto->faturas()->attach($sinal->id, ['tipo' => 'sinal']);
        $projeto->faturas()->attach($saldo->id, ['tipo' => 'saldo_final']);

        // Desconto de 10.000 + IVA no saldo: o recibo do saldo só pode ir até 68.400 − 11.400 = 57.000
        // (é o teto do ReciboService), por isso o projeto tem de ficar quitado nesse valor.
        $this->notaCreditoEmitida($saldo, 11400, 10000);

        $this->assertSame(102600.0, $projeto->valorDevido());

        $projeto->recibos()->attach($this->reciboEmitido($sinal, 45600)->id);
        $projeto->recibos()->attach($this->reciboEmitido($saldo, 57000)->id);

        $this->assertSame(0.0, $projeto->saldoDevedor());
        $this->assertTrue($projeto->financeiramenteQuitado());
    }

    public function test_recibo_emitido_no_faturacao_sem_passar_pelo_estudio_tambem_quita(): void
    {
        $projeto = $this->projeto();
        $sinal = $this->faturacao->gerarFaturaSinal($projeto);
        $saldo = $this->faturacao->gerarFaturaSaldoFinal($projeto);

        // Pagamentos registados no ecrã de Recibos do Faturação: não ficam ligados ao projeto
        // pela tabela do Estúdio, mas são recibos das faturas do projeto.
        $recibos = app(ReciboService::class);
        $recibos->emitir($this->empresa->id, $this->cliente->id, $sinal->valor_total, 'multicaixa', $sinal->id);
        $recibos->emitir($this->empresa->id, $this->cliente->id, $saldo->valor_total, 'transferencia', $saldo->id);

        $this->assertSame(114000.0, $projeto->valorTotalRecebido());
        $this->assertTrue($projeto->financeiramenteQuitado());
    }

    public function test_recibo_ligado_pelas_duas_vias_nao_conta_a_dobrar(): void
    {
        $projeto = $this->projeto();
        $sinal = $this->faturacao->gerarFaturaSinal($projeto);

        $recibo = $this->faturacao->registrarRecibo($projeto, $sinal, 'multicaixa');

        $this->assertSame(45600.0, $projeto->valorTotalRecebido());
        $this->assertSame($recibo->valor, '45600.00');
    }

    public function test_pagamento_por_omissao_recebe_o_que_falta_depois_de_uma_nota_de_credito(): void
    {
        $projeto = $this->projeto('hora');
        $fatura = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 30000);
        $projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);
        $this->notaCreditoEmitida($fatura, 5000);

        $recibo = $this->faturacao->registrarRecibo($projeto, $fatura, 'multicaixa');

        $this->assertSame('25000.00', $recibo->valor);
        $this->assertTrue($projeto->financeiramenteQuitado());
    }

    public function test_projeto_cancelado_nao_pode_ser_faturado(): void
    {
        $projeto = $this->projeto('pacote', ['estado' => 'cancelado']);

        $this->expectException(\InvalidArgumentException::class);

        $this->faturacao->gerarFaturaSinal($projeto);
    }

    public function test_sessao_cobrada_ao_cêntimo_e_nao_a_milesimos_de_hora(): void
    {
        // 20 minutos a 5.000 Kz/h = 1.666,67 (antes: 0,333 h × 5.000 = 1.665,00).
        $sessao = $this->sessaoConcluida(20);

        $fatura = $this->faturacao->cobrarSessao($sessao);

        $this->assertSame('1666.67', $fatura->valor_sem_iva);
        $this->assertSame($fatura->id, $sessao->fresh()->fatura_id);
    }

    public function test_cupao_de_aniversario_aplica_se_ao_total_exato_da_sessao(): void
    {
        $cupom = CupomDesconto::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'codigo' => 'ANIV-TESTE1',
            'percentual_desconto' => 15,
            'motivo' => 'aniversario',
            'valido_ate' => now()->addDays(10),
        ]);

        // 1h20 a 5.000 Kz/h = 6.666,67; menos 15% (1.000,00) = 5.666,67.
        $fatura = $this->faturacao->cobrarSessao($this->sessaoConcluida(80));

        $this->assertSame('5666.67', $fatura->valor_sem_iva);
        $this->assertNotNull($cupom->fresh()->usado_em);
        $this->assertStringContainsString('ANIV-TESTE1', $fatura->linhas->first()->descricao);
    }

    public function test_sessao_de_projeto_por_pacote_apagado_nao_e_faturada_a_hora(): void
    {
        $projeto = $this->projeto();
        $sessao = $this->sessaoConcluida(60, $projeto);
        $projeto->delete();

        $this->assertNull($this->faturacao->cobrarSessao($sessao->fresh()));
    }

    public function test_sessao_por_hora_liga_a_fatura_ao_projeto(): void
    {
        $projeto = $this->projeto('hora');
        $sessao = $this->sessaoConcluida(60, $projeto);

        $fatura = $this->faturacao->cobrarSessao($sessao);

        $this->assertSame('5000.00', $fatura->valor_sem_iva);
        $this->assertSame(5700.0, $projeto->valorTotalFaturado());
        $this->assertNull($this->faturacao->cobrarSessao($sessao->fresh()), 'Segunda cobrança da mesma sessão não emite outra fatura.');
    }
    public function test_sinal_nao_e_emitido_como_ft_quando_agt_esta_ativa(): void
    {
        config(['faturacao.agt.ativo' => true]);

        $this->expectException(\\DomainException::class);
        $this->expectExceptionMessage('tipo FA');

        $this->faturacao->gerarFaturaSinal($this->projeto());
    }

}
