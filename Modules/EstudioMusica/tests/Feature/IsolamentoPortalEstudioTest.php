<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
use Modules\EstudioMusica\Tests\Support\CriaEstudioComDados;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

require_once __DIR__.'/../Support/ComEstudioAutenticado.php';
require_once __DIR__.'/../Support/CriaEstudioComDados.php';

/**
 * Isolamento no Portal do Cliente, que se autentica só por token (sem login).
 * O token do artista A1 do estúdio A:
 *  - nunca alcança versões de outro artista do MESMO estúdio (403);
 *  - nunca alcança versões de OUTRO estúdio (404, o registo nem «existe»);
 *  - só funciona enquanto não expira.
 */
class IsolamentoPortalEstudioTest extends TestCase
{
    use ComEstudioAutenticado;
    use CriaEstudioComDados;
    use RefreshDatabase;

    private const TOKEN = 'token-do-artista-a1';

    /** @var array<string, mixed> Estúdio A, artista A1 (o dono do token). */
    protected array $a1;

    /** @var array<string, mixed> Outro artista (A2) do mesmo estúdio A. */
    protected array $a2;

    /** @var array<string, mixed> Estúdio B, de outra empresa. */
    protected array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a1 = $this->criarEstudioComDados('510000501', 'SEGREDO-A1');
        $this->b = $this->criarEstudioComDados('510000502', 'SEGREDO-B');
        $this->a2 = $this->outroArtistaDoMesmoEstudio($this->a1, 'SEGREDO-A2');

        $this->darTokenAoArtista($this->a1['cliente'], self::TOKEN);
    }

    public function test_o_portal_so_mostra_os_projetos_do_proprio_artista(): void
    {
        $this->get(route('estudiomusica.portal.mostrar', ['token' => self::TOKEN]))
            ->assertOk()
            ->assertSee('SEGREDO-A1')
            ->assertDontSee('SEGREDO-A2')
            ->assertDontSee('SEGREDO-B');
    }

    public function test_token_de_um_artista_nao_alcanca_versoes_de_outro_artista_do_mesmo_estudio(): void
    {
        $antes = $this->fotografia();

        foreach ($this->acoesSobreUmaVersao($this->a2['versao']) as [$metodo, $url, $dados]) {
            $resposta = $this->call($metodo, $url, $dados);

            $this->assertContains(
                $resposta->getStatusCode(),
                [403, 404],
                "{$metodo} {$url} devia ser recusado (recebeu {$resposta->getStatusCode()})."
            );
        }

        $this->assertSame($antes, $this->fotografia(), 'Nenhuma versão ou marcador do outro artista pode ter sido tocado.');
    }

    public function test_token_nao_alcanca_versoes_de_outro_estudio(): void
    {
        $antes = $this->fotografia();

        foreach ($this->acoesSobreUmaVersao($this->b['versao']) as [$metodo, $url, $dados]) {
            $this->call($metodo, $url, $dados)->assertNotFound();
        }

        $this->assertSame($antes, $this->fotografia(), 'Nenhuma versão ou marcador do outro estúdio pode ter sido tocado.');
    }

    public function test_token_invalido_ou_expirado_e_recusado(): void
    {
        $this->get(route('estudiomusica.portal.mostrar', ['token' => 'token-que-nao-existe']))->assertForbidden();

        DB::table('clientes')
            ->where('id', $this->a1['cliente']->id)
            ->update(['token_portal_expira_em' => now()->subMinute()]);

        $this->get(route('estudiomusica.portal.mostrar', ['token' => self::TOKEN]))->assertForbidden();
    }

    /**
     * Todas as ações do portal que recebem um ID de versão.
     *
     * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    protected function acoesSobreUmaVersao(VersaoAudio $versao): array
    {
        $parametros = ['token' => self::TOKEN, 'versao' => $versao->id];

        return [
            ['GET', route('estudiomusica.portal.versoes.reproduzir', $parametros), []],
            ['GET', route('estudiomusica.portal.versoes.descarregar', $parametros), []],
            ['POST', route('estudiomusica.portal.versoes.comentar', $parametros), ['tempo_segundos' => 10, 'comentario' => 'Comentário do intruso']],
            ['POST', route('estudiomusica.portal.versoes.aprovar', $parametros), []],
            ['POST', route('estudiomusica.portal.versoes.ajustes', $parametros), ['comentario' => 'Ajustes do intruso']],
        ];
    }

    protected function darTokenAoArtista(Cliente $cliente, string $token): void
    {
        DB::table('clientes')->where('id', $cliente->id)->update([
            'token_portal' => hash('sha256', $token),
            'token_portal_expira_em' => now()->addDay(),
        ]);
    }

    /**
     * Segundo artista no mesmo estúdio, com projeto, faixa e versão próprios.
     *
     * @param  array<string, mixed>  $estudio
     * @return array<string, mixed>
     */
    protected function outroArtistaDoMesmoEstudio(array $estudio, string $marca): array
    {
        $empresaId = (int) $estudio['admin']->empresa_id;

        $cliente = Cliente::create(['empresa_id' => $empresaId, 'nome' => "Artista {$marca}"]);
        $projeto = ProjetoMusical::create([
            'empresa_id' => $empresaId,
            'cliente_id' => $cliente->id,
            'nome' => "Projeto {$marca}",
            'tipo_cobranca' => 'hora',
        ]);
        $faixa = FaixaMusical::create([
            'empresa_id' => $empresaId,
            'projeto_musical_id' => $projeto->id,
            'nome' => "Faixa {$marca}",
            'estado' => 'agendado',
            'ordem' => 1,
        ]);
        $versao = VersaoAudio::create([
            'empresa_id' => $empresaId,
            'faixa_musical_id' => $faixa->id,
            'rotulo' => "Versão {$marca}",
            'caminho_ficheiro' => "estudio/{$marca}.mp3",
            'estado' => 'pendente_aprovacao',
            'enviada_em' => now(),
        ]);

        return compact('cliente', 'projeto', 'faixa', 'versao');
    }

    /** O estado das versões e dos marcadores de todas as empresas num instante. */
    protected function fotografia(): array
    {
        return [
            'versoes_audio' => DB::table('versoes_audio')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
            'marcadores_audio' => DB::table('marcadores_audio')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
            'notification_outbox' => DB::table('notification_outbox')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
        ];
    }
}
