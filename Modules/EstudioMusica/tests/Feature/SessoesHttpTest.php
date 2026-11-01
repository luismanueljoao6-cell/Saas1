<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
use Modules\EstudioMusica\Tests\Support\CriaDocumentosFiscais;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

require_once __DIR__.'/../Support/ComEstudioAutenticado.php';
require_once __DIR__.'/../Support/CriaDocumentosFiscais.php';

/** Sessões e faturação vistas pelo staff: o que o utilizador carrega nos botões. */
class SessoesHttpTest extends TestCase
{
    use ComEstudioAutenticado;
    use CriaDocumentosFiscais;
    use RefreshDatabase;

    protected User $admin;

    protected Cliente $cliente;

    protected SalaEstudio $sala;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararChaveFiscal();
        config(['faturacao.taxa_iva_geral' => 14.0]);

        $this->admin = $this->registarEstudio('510000201');
        $this->noTenantDe($this->admin);

        $this->cliente = Cliente::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Artista Sessões']);
        $this->sala = SalaEstudio::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Sala A', 'preco_hora' => 5000]);
    }

    protected function projeto(string $tipo = 'hora', array $extra = []): ProjetoMusical
    {
        return ProjetoMusical::create(array_merge([
            'empresa_id' => $this->admin->empresa_id,
            'cliente_id' => $this->cliente->id,
            'nome' => 'Projeto '.$tipo,
            'tipo_cobranca' => $tipo,
            'valor_pacote' => $tipo === 'pacote' ? 100000 : null,
            'percentual_sinal' => 40,
        ], $extra));
    }

    protected function sessao(string $estado, Carbon $inicio, int $minutos = 60, array $extra = []): SessaoEstudio
    {
        $sessao = new SessaoEstudio;
        $sessao->forceFill([
            'empresa_id' => $this->admin->empresa_id,
            'sala_estudio_id' => $this->sala->id,
            'cliente_id' => $this->cliente->id,
            'tipo_servico' => 'mixagem',
            'inicio_previsto' => $inicio,
            'fim_previsto' => $inicio->copy()->addMinutes($minutos),
            'estado' => $estado,
        ] + $extra)->save();

        return $sessao;
    }

    protected function dadosDaSessao(array $extra = []): array
    {
        return array_merge([
            'sala_estudio_id' => $this->sala->id,
            'cliente_id' => $this->cliente->id,
            'tipo_servico' => 'mixagem',
            'inicio_previsto' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'fim_previsto' => now()->addDays(2)->setTime(12, 0)->format('Y-m-d\TH:i'),
        ], $extra);
    }

    public function test_check_out_grava_o_fim_real_mesmo_quando_o_limite_de_faturas_foi_atingido(): void
    {
        $this->limitarFaturasPorMes(0);
        $sessao = $this->sessao('em_curso', now()->subMinutes(80), 80, ['inicio_real' => now()->subMinutes(80)]);

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.sessoes.check-out', $sessao->id))
            ->assertRedirect()
            ->assertSessionHas('erro', fn (string $mensagem) => str_contains($mensagem, 'NÃO foi emitida'));

        $this->noTenantDe($this->admin);
        $sessao->refresh();

        // O estúdio não fica com a sessão "em curso" para sempre (nem com o relógio a contar horas
        // a mais): o fim real fica gravado e a fatura emite-se depois.
        $this->assertSame('concluida', $sessao->estado);
        $this->assertNotNull($sessao->fim_real);
        $this->assertNull($sessao->fatura_id);

        $fimOriginal = $sessao->fim_real->toDateTimeString();
        $this->limitarFaturasPorMes(null);
        $this->travel(3)->hours();

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.sessoes.faturar', $sessao->id))
            ->assertSessionHas('sucesso');

        $this->noTenantDe($this->admin);
        $sessao->refresh();

        $this->assertNotNull($sessao->fatura_id);
        $this->assertSame($fimOriginal, $sessao->fim_real->toDateTimeString(), 'Faturar mais tarde não pode mexer na hora de fim.');
    }

    public function test_check_out_normal_continua_a_faturar_na_hora(): void
    {
        $sessao = $this->sessao('em_curso', now()->subMinutes(60), 60, ['inicio_real' => now()->subMinutes(60)]);

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.sessoes.check-out', $sessao->id))
            ->assertSessionHas('sucesso', fn (string $mensagem) => str_contains($mensagem, 'emitida'));

        $this->noTenantDe($this->admin);
        $this->assertNotNull($sessao->fresh()->fatura_id);
    }

    public function test_faturar_so_aceita_sessoes_concluidas_e_nao_duplica_a_fatura(): void
    {
        $agendada = $this->sessao('agendada', now()->addDay());

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.sessoes.faturar', $agendada->id))
            ->assertSessionHas('erro');

        $concluida = $this->sessao('concluida', now()->subHours(3), 60, ['inicio_real' => now()->subHours(3), 'fim_real' => now()->subHours(2)]);

        $this->actingAs($this->admin)->post(route('estudiomusica.sessoes.faturar', $concluida->id))->assertSessionHas('sucesso');
        $this->actingAs($this->admin)->post(route('estudiomusica.sessoes.faturar', $concluida->id))->assertSessionHas('erro');

        $this->noTenantDe($this->admin);
        $this->assertSame(1, \Modules\Faturacao\Models\Fatura::query()->count());
    }

    public function test_sinal_e_saldo_com_limite_atingido_mostram_um_erro_em_vez_de_rebentar(): void
    {
        $projeto = $this->projeto('pacote');
        $this->limitarFaturasPorMes(0);

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.projetos.sinal', $projeto->id))
            ->assertRedirect()
            ->assertSessionHas('erro');

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.projetos.saldo-final', $projeto->id))
            ->assertRedirect()
            ->assertSessionHas('erro');
    }

    public function test_sessao_pode_ser_agendada_ligada_a_um_projeto_e_a_um_engenheiro(): void
    {
        $projeto = $this->projeto();

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.sessoes.store'), $this->dadosDaSessao([
                'projeto_musical_id' => $projeto->id,
                'engenheiro_user_id' => $this->admin->id,
            ]))
            ->assertSessionHas('sucesso');

        $this->noTenantDe($this->admin);
        $sessao = SessaoEstudio::query()->sole();

        $this->assertSame($projeto->id, $sessao->projeto_musical_id);
        $this->assertSame($this->admin->id, $sessao->engenheiro_user_id);
    }

    public function test_projeto_de_outro_cliente_cancelado_ou_apagado_nao_aceita_sessoes(): void
    {
        $outro = Cliente::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Outro Artista']);
        $deOutro = ProjetoMusical::create(['empresa_id' => $this->admin->empresa_id, 'cliente_id' => $outro->id, 'nome' => 'Alheio', 'tipo_cobranca' => 'hora']);
        $cancelado = $this->projeto('hora', ['estado' => 'cancelado']);
        $apagado = $this->projeto('hora');
        $apagado->delete();

        foreach ([$deOutro, $cancelado, $apagado] as $projeto) {
            $this->actingAs($this->admin)
                ->post(route('estudiomusica.sessoes.store'), $this->dadosDaSessao(['projeto_musical_id' => $projeto->id]))
                ->assertSessionHasErrors('projeto_musical_id');
        }

        $this->noTenantDe($this->admin);
        $this->assertSame(0, SessaoEstudio::query()->count());
    }

    public function test_sessao_com_duracao_absurda_e_recusada(): void
    {
        // Sem limite, um erro de digitação (ano errado) bloqueava a sala durante meses.
        $this->actingAs($this->admin)
            ->post(route('estudiomusica.sessoes.store'), $this->dadosDaSessao([
                'fim_previsto' => now()->addDays(40)->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHas('erro');

        $this->noTenantDe($this->admin);
        $this->assertSame(0, SessaoEstudio::query()->count());
    }

    public function test_reagendar_muda_o_horario_e_respeita_conflitos_e_estados(): void
    {
        $amanha = now()->addDay()->setTime(10, 0);
        $sessao = $this->sessao('agendada', $amanha, 60);
        $this->sessao('agendada', $amanha->copy()->addHours(5), 60); // ocupa 15:00–16:00

        $novo = fn (int $hora, int $duracao = 1) => [
            'inicio_previsto' => $amanha->copy()->setTime($hora, 0)->format('Y-m-d\TH:i'),
            'fim_previsto' => $amanha->copy()->setTime($hora + $duracao, 0)->format('Y-m-d\TH:i'),
        ];

        $this->actingAs($this->admin)
            ->put(route('estudiomusica.sessoes.reagendar', $sessao->id), $novo(15))
            ->assertSessionHas('erro');

        $this->actingAs($this->admin)
            ->put(route('estudiomusica.sessoes.reagendar', $sessao->id), $novo(13, 2))
            ->assertSessionHas('sucesso');

        $this->noTenantDe($this->admin);
        $this->assertSame('13:00', $sessao->fresh()->inicio_previsto->format('H:i'));

        $sessao->update(['estado' => 'cancelada']);

        $this->actingAs($this->admin)
            ->put(route('estudiomusica.sessoes.reagendar', $sessao->id), $novo(9))
            ->assertSessionHas('erro');
    }

    public function test_ecra_de_sessoes_oferece_projeto_engenheiro_e_as_acoes_de_cada_sessao(): void
    {
        $this->projeto('hora');
        $this->sessao('agendada', now()->addDay(), 60);
        $this->sessao('concluida', now()->subHours(3), 60, ['inicio_real' => now()->subHours(3), 'fim_real' => now()->subHours(2)]);

        $this->actingAs($this->admin)
            ->get(route('estudiomusica.sessoes.index'))
            ->assertOk()
            ->assertSee('name="projeto_musical_id"', false)
            ->assertSee('name="engenheiro_user_id"', false)
            ->assertSee('Cancelar')
            ->assertSee('Reagendar')
            ->assertSee('Faturar');
    }

    public function test_ecra_de_sessoes_pre_seleciona_o_projeto_do_link_da_pagina_do_projeto(): void
    {
        $projeto = $this->projeto('hora');

        $this->actingAs($this->admin)
            ->get(route('estudiomusica.sessoes.index', ['projeto' => $projeto->id]))
            ->assertOk()
            ->assertSee('value="'.$projeto->id.'" selected', false);
    }
}
