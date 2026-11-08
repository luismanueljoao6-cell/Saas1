<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
use Modules\EstudioMusica\Tests\Support\CriaEstudioComDados;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__.'/../Support/ComEstudioAutenticado.php';
require_once __DIR__.'/../Support/CriaEstudioComDados.php';

/**
 * Isolamento entre estúdios (empresas) na área da equipa. O Administrador do
 * estúdio B é o «intruso»: tenta ler e alterar, por ID, tudo o que pertence
 * ao estúdio A. Em todos os casos a resposta tem de ser 404 (nunca 200,
 * 302 de sucesso ou 403, que confirmariam que o registo existe) e a base de
 * dados tem de ficar exatamente como estava.
 */
class IsolamentoEstudioTest extends TestCase
{
    use ComEstudioAutenticado;
    use CriaEstudioComDados;
    use RefreshDatabase;

    /** Tabelas de negócio onde um intruso nunca pode deixar rasto. */
    private const TABELAS = [
        'clientes',
        'salas_estudio',
        'projetos_musicais',
        'faixas_musicais',
        'versoes_audio',
        'sessoes_estudio',
        'solicitacoes_orcamento',
        'reclamacoes_estudio',
        'faturas',
        'recibos',
        'notification_outbox',
    ];

    /** @var array<string, mixed> Recursos do estúdio A (o alvo). */
    protected array $a;

    /** @var array<string, mixed> Recursos do estúdio B (o intruso). */
    protected array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->criarEstudioComDados('510000401', 'SEGREDO-A');
        $this->b = $this->criarEstudioComDados('510000402', 'SEGREDO-B');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: array<string, mixed>}>
     */
    public static function rotasComId(): array
    {
        $estado = 'mixagem';

        return [
            'projeto: ver' => ['GET', 'estudiomusica.projetos.show', ['projeto' => 'projeto'], []],
            'projeto: estado' => ['PUT', 'estudiomusica.projetos.estado', ['projeto' => 'projeto'], ['estado' => $estado]],
            'projeto: portfólio' => ['PUT', 'estudiomusica.projetos.portfolio', ['projeto' => 'projeto'], ['destaque_portfolio' => 0]],
            'projeto: link do portal' => ['POST', 'estudiomusica.projetos.link-portal', ['projeto' => 'projeto'], []],
            'projeto: sinal' => ['POST', 'estudiomusica.projetos.sinal', ['projeto' => 'projeto'], []],
            'projeto: saldo final' => ['POST', 'estudiomusica.projetos.saldo-final', ['projeto' => 'projeto'], []],
            'projeto: recibo' => ['POST', 'estudiomusica.projetos.recibos', ['projeto' => 'projeto'], ['fatura_id' => 1, 'meio_pagamento' => 'dinheiro']],
            'projeto: nova faixa' => ['POST', 'estudiomusica.projetos.faixas.store', ['projeto' => 'projeto'], ['nome' => 'Faixa do intruso']],
            'faixa: estado' => ['PUT', 'estudiomusica.faixas.estado', ['faixa' => 'faixa'], ['estado' => $estado]],
            'faixa: nova versão' => ['POST', 'estudiomusica.faixas.versoes.store', ['faixa' => 'faixa'], ['rotulo' => 'Versão do intruso', 'ficheiro' => 'AUDIO']],
            'versão: reproduzir' => ['GET', 'estudiomusica.versoes.reproduzir', ['versao' => 'versao'], []],
            'versão: lembrete de aprovação' => ['POST', 'estudiomusica.versoes.lembrete-aprovacao', ['versao' => 'versao'], []],
            'sala: atualizar' => ['PUT', 'estudiomusica.salas.update', ['sala' => 'sala'], ['nome' => 'Sala do intruso', 'preco_hora' => 1]],
            'cliente: perfil de artista' => ['PUT', 'estudiomusica.clientes.perfil-artista', ['cliente' => 'cliente'], ['nome_artistico' => 'Intruso']],
            'sessão: reagendar' => ['PUT', 'estudiomusica.sessoes.reagendar', ['sessao' => 'sessao'], ['inicio_previsto' => '2031-01-01 10:00:00', 'fim_previsto' => '2031-01-01 11:00:00']],
            'sessão: cancelar' => ['DELETE', 'estudiomusica.sessoes.cancelar', ['sessao' => 'sessao'], []],
            'sessão: check-in' => ['POST', 'estudiomusica.sessoes.check-in', ['sessao' => 'sessao'], []],
            'sessão: check-out' => ['POST', 'estudiomusica.sessoes.check-out', ['sessao' => 'sessao'], []],
            'sessão: faturar' => ['POST', 'estudiomusica.sessoes.faturar', ['sessao' => 'sessao'], []],
            'solicitação: estado' => ['PUT', 'estudiomusica.solicitacoes.estado', ['solicitacao' => 'solicitacao'], ['estado' => 'descartado']],
            'solicitação: converter' => ['POST', 'estudiomusica.solicitacoes.converter', ['solicitacao' => 'solicitacao'], []],
            'reclamação: atualizar' => ['PUT', 'estudiomusica.reclamacoes.atualizar', ['reclamacao' => 'reclamacao'], ['estado' => 'resolvida', 'resposta' => 'Resposta do intruso']],
        ];
    }

    /**
     * @param  array<string, string>  $parametros  nome do parâmetro de rota => chave do recurso do estúdio A
     * @param  array<string, mixed>  $dados
     */
    #[DataProvider('rotasComId')]
    public function test_staff_de_outro_estudio_recebe_404_e_nada_muda(string $metodo, string $rota, array $parametros, array $dados): void
    {
        $antes = $this->fotografia();

        $ficheiros = [];
        if (($dados['ficheiro'] ?? null) === 'AUDIO') {
            unset($dados['ficheiro']);
            $ficheiros['ficheiro'] = UploadedFile::fake()->create('intruso.mp3', 100, 'audio/mpeg');
        }

        $url = route($rota, array_map(fn (string $chave) => $this->a[$chave]->id, $parametros));

        $this->actingAs($this->b['admin'])
            ->call($metodo, $url, $dados, [], $ficheiros)
            ->assertNotFound();

        $this->assertSame($antes, $this->fotografia(), 'Um pedido do estúdio B não pode alterar nenhuma tabela de negócio.');
    }

    public function test_listagens_so_mostram_dados_do_proprio_estudio(): void
    {
        $listagens = [
            'estudiomusica.projetos.index',
            'estudiomusica.salas.index',
            'estudiomusica.solicitacoes.index',
            'estudiomusica.reclamacoes.index',
            'estudiomusica.sessoes.index',
        ];

        foreach ($listagens as $rota) {
            $this->actingAs($this->b['admin'])
                ->get(route($rota))
                ->assertOk()
                ->assertDontSee('SEGREDO-A');
        }

        // Controlo positivo: as páginas mostram mesmo dados quando são do próprio estúdio.
        $this->actingAs($this->b['admin'])->get(route('estudiomusica.projetos.index'))->assertSee('SEGREDO-B');
        $this->actingAs($this->b['admin'])->get(route('estudiomusica.salas.index'))->assertSee('SEGREDO-B');
        $this->actingAs($this->b['admin'])->get(route('estudiomusica.solicitacoes.index'))->assertSee('SEGREDO-B');
        $this->actingAs($this->b['admin'])->get(route('estudiomusica.reclamacoes.index'))->assertSee('SEGREDO-B');
    }

    public function test_administrador_de_outro_estudio_nao_atribui_nem_remove_papeis_a_utilizadores_do_estudio_a(): void
    {
        $papeisAntes = DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_id')->get()->map(fn ($r) => (array) $r)->all();

        foreach (['estudiomusica.equipa.atribuir', 'estudiomusica.equipa.remover'] as $rota) {
            $this->actingAs($this->b['admin'])
                ->post(route($rota), ['utilizador_id' => $this->a['admin']->id, 'papel' => 'Recepcao'])
                ->assertSessionHasErrors('utilizador_id');
        }

        $papeisDepois = DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame($papeisAntes, $papeisDepois, 'Nenhum papel pode ter sido atribuído ou removido.');
    }

    public function test_nao_se_cria_projeto_nem_sessao_com_referencias_do_outro_estudio(): void
    {
        $antes = $this->fotografia();

        // O cliente é do estúdio A: a validação tem de o recusar.
        $this->actingAs($this->b['admin'])
            ->post(route('estudiomusica.projetos.store'), [
                'cliente_id' => $this->a['cliente']->id,
                'nome' => 'Projeto com cliente alheio',
                'tipo_cobranca' => 'hora',
            ])
            ->assertSessionHasErrors('cliente_id');

        // Sala, cliente e projeto são do estúdio A.
        $this->actingAs($this->b['admin'])
            ->post(route('estudiomusica.sessoes.store'), [
                'sala_estudio_id' => $this->a['sala']->id,
                'cliente_id' => $this->a['cliente']->id,
                'projeto_musical_id' => $this->a['projeto']->id,
                'tipo_servico' => 'mixagem',
                'inicio_previsto' => now()->addDays(3)->format('Y-m-d H:00:00'),
                'fim_previsto' => now()->addDays(3)->addHour()->format('Y-m-d H:00:00'),
            ])
            ->assertSessionHasErrors(['sala_estudio_id', 'cliente_id', 'projeto_musical_id']);

        $this->assertSame($antes, $this->fotografia());
    }

    /** O estado completo das tabelas de negócio (de todas as empresas) num instante. */
    protected function fotografia(): array
    {
        $estado = [];

        foreach (self::TABELAS as $tabela) {
            $estado[$tabela] = DB::table($tabela)->orderBy('id')->get()->map(fn ($linha) => (array) $linha)->all();
        }

        return $estado;
    }
}
