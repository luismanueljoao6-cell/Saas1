<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Models\Serie;
use Tests\TestCase;

/**
 * Cobre a regra mais sensível do módulo (secção C): "Download de arquivos
 * liberado apenas após a quitação financeira do saldo do projeto".
 * Testa ProjetoMusical::financeiramenteQuitado() diretamente — é o único
 * método que PortalClienteController::descarregar() consulta, por isso
 * testar aqui cobre a regra de negócio sem precisar de simular upload de
 * ficheiro nem o middleware de token do portal.
 *
 * Fatura/Recibo são criados com forceFill(), não create(), para o teste
 * não depender de adivinhar exatamente o que está no $fillable desses
 * models do módulo Faturacao — só nos interessa aqui o estado final das
 * colunas, não simular o fluxo de emissão completo (isso já está coberto
 * por Faturacao\Tests\Feature\CadeiaFiscalTest).
 */
class LiberacaoDownloadAposQuitacaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected ProjetoMusical $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Teste', 'nif' => '400000002']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Artista Teste']);

        $this->projeto = ProjetoMusical::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'nome' => 'Projeto Teste',
            'tipo_cobranca' => 'pacote',
            'valor_pacote' => 100000,
        ]);
    }

    protected function criarFaturaEmitida(float $valor): Fatura
    {
        $serie = Serie::create([
            'empresa_id' => $this->empresa->id,
            'tipo_documento' => 'FT',
            'ano' => 2026,
            'prefixo' => 'A',
            'ultimo_numero' => 1,
        ]);

        $fatura = new Fatura;
        $fatura->forceFill([
            'empresa_id' => $this->empresa->id,
            'serie_id' => $serie->id,
            'cliente_id' => $this->cliente->id,
            'numero_sequencial' => 1,
            'numero_documento' => 'FT A2026/1',
            'estado' => 'emitida',
            'valor_total' => $valor,
        ])->save();

        return $fatura;
    }

    protected function criarReciboEmitido(Fatura $fatura, float $valor): Recibo
    {
        $recibo = new Recibo;
        $recibo->forceFill([
            'empresa_id' => $this->empresa->id,
            'serie_id' => $fatura->serie_id,
            'cliente_id' => $this->cliente->id,
            'fatura_id' => $fatura->id,
            'numero_sequencial' => 1,
            'numero_documento' => 'RC A2026/1',
            'estado' => 'emitido',
            'valor' => $valor,
            'meio_pagamento' => 'multicaixa',
        ])->save();

        return $recibo;
    }

    public function test_projeto_sem_nenhuma_fatura_emitida_nao_esta_quitado(): void
    {
        $this->assertFalse($this->projeto->financeiramenteQuitado());
    }

    public function test_projeto_faturado_mas_ainda_nao_pago_nao_esta_quitado(): void
    {
        $fatura = $this->criarFaturaEmitida(100000);
        $this->projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);

        $this->assertFalse($this->projeto->financeiramenteQuitado());
        $this->assertSame(100000.0, $this->projeto->saldoDevedor());
    }

    public function test_projeto_totalmente_pago_fica_quitado(): void
    {
        $fatura = $this->criarFaturaEmitida(100000);
        $this->projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);

        $recibo = $this->criarReciboEmitido($fatura, 100000);
        $this->projeto->recibos()->attach($recibo->id);

        $this->assertTrue($this->projeto->financeiramenteQuitado());
        $this->assertSame(0.0, $this->projeto->saldoDevedor());
    }

    public function test_pagamento_parcial_nao_e_suficiente_para_quitar(): void
    {
        $fatura = $this->criarFaturaEmitida(100000);
        $this->projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);

        $recibo = $this->criarReciboEmitido($fatura, 40000);
        $this->projeto->recibos()->attach($recibo->id);

        $this->assertFalse($this->projeto->financeiramenteQuitado());
        $this->assertSame(60000.0, $this->projeto->saldoDevedor());
    }

    /**
     * O cenário que motivou a regra de valorDevido(): pagar só o sinal de
     * um pacote NÃO pode libertar os ficheiros, mesmo que tudo o que foi
     * faturado até agora esteja pago — falta faturar e receber o resto do
     * pacote.
     */
    public function test_sinal_pago_de_um_pacote_nao_liberta_o_download(): void
    {
        $sinal = $this->criarFaturaEmitida(40000);
        $this->projeto->faturas()->attach($sinal->id, ['tipo' => 'sinal']);

        $recibo = $this->criarReciboEmitido($sinal, 40000);
        $this->projeto->recibos()->attach($recibo->id);

        $this->assertFalse($this->projeto->financeiramenteQuitado());
        $this->assertSame(60000.0, $this->projeto->saldoDevedor());
    }

    public function test_projeto_por_hora_fica_quitado_quando_tudo_o_que_foi_faturado_esta_pago(): void
    {
        $porHora = ProjetoMusical::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'nome' => 'Sessões avulsas',
            'tipo_cobranca' => 'hora',
        ]);

        $fatura = $this->criarFaturaEmitida(30000);
        $porHora->faturas()->attach($fatura->id, ['tipo' => 'avulso']);

        $recibo = $this->criarReciboEmitido($fatura, 30000);
        $porHora->recibos()->attach($recibo->id);

        $this->assertTrue($porHora->financeiramenteQuitado());
        $this->assertSame(0.0, $porHora->saldoDevedor());
    }
}
