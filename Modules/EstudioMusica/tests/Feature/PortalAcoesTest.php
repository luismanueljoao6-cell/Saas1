<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Ações do portal sobre uma versão (/portal/{token}/versoes/{versao}/...): o prefixo {token} chega ao
 * controller por posição, antes de {versao}. Com um token que não é número, uma assinatura sem
 * `string $token` dava TypeError em todas estas ações.
 */
class PortalAcoesTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-de-teste-portal-0123456789abcdef0123456789';

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected VersaoAudio $versao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Portal', 'nif' => '400000008']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->cliente = (new Cliente)->forceFill([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Artista Portal',
            'token_portal' => hash('sha256', self::TOKEN),
            'token_portal_expira_em' => now()->addDay(),
        ]);
        $this->cliente->save();

        $projeto = ProjetoMusical::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'nome' => 'Projeto Portal',
            'tipo_cobranca' => 'hora',
        ]);
        $faixa = FaixaMusical::create([
            'empresa_id' => $this->empresa->id,
            'projeto_musical_id' => $projeto->id,
            'nome' => 'Faixa 1',
            'ordem' => 1,
        ]);
        $this->versao = VersaoAudio::create([
            'empresa_id' => $this->empresa->id,
            'faixa_musical_id' => $faixa->id,
            'rotulo' => 'Mix v1',
            'caminho_ficheiro' => 'estudiomusica/versoes-audio/inexistente.wav',
            'estado' => 'pendente_aprovacao',
            'enviada_em' => now(),
        ]);
    }

    protected function rota(string $nome): string
    {
        return route("estudiomusica.portal.{$nome}", ['token' => self::TOKEN, 'versao' => $this->versao->id]);
    }

    public function test_o_cliente_comenta_num_ponto_da_faixa(): void
    {
        $this->post($this->rota('versoes.comentar'), ['tempo_segundos' => 12.5, 'comentario' => 'Mais graves aqui'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sucesso');

        $marcador = $this->versao->marcadores()->firstOrFail();
        $this->assertSame('Mais graves aqui', $marcador->comentario);
        $this->assertTrue((bool) $marcador->criado_por_cliente);
        $this->assertSame('Artista Portal', $marcador->nome_autor);
    }

    public function test_comentar_valida_os_campos(): void
    {
        $this->post($this->rota('versoes.comentar'), ['tempo_segundos' => 12.5])->assertSessionHasErrors(['comentario']);
        $this->post($this->rota('versoes.comentar'), ['comentario' => 'Sem tempo'])->assertSessionHasErrors(['tempo_segundos']);

        $this->assertSame(0, $this->versao->marcadores()->count());
    }

    public function test_o_cliente_pede_ajustes(): void
    {
        $this->post($this->rota('versoes.ajustes'))->assertSessionHas('sucesso');

        $this->assertSame('ajustes_solicitados', $this->versao->fresh()->estado);

        // Já tem decisão: não se troca por outra.
        $this->post($this->rota('versoes.ajustes'))->assertSessionHas('erro');
    }

    public function test_descarregar_sem_quitacao_volta_com_a_mensagem(): void
    {
        $this->get($this->rota('versoes.descarregar'))
            ->assertRedirect()
            ->assertSessionHas('erro');
    }

    public function test_reproduzir_sem_quitacao_so_serve_a_pre_visualizacao(): void
    {
        // O ficheiro da versão não existe: não há pré-visualização para servir (503), e nunca o original.
        $this->get($this->rota('versoes.reproduzir'))->assertStatus(503);
    }

    public function test_portal_nao_serve_versoes_de_outro_cliente(): void
    {
        $outro = (new Cliente)->forceFill([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Outro Artista',
            'token_portal' => hash('sha256', 'outro-token-de-teste-portal-0123456789abcdef012345'),
            'token_portal_expira_em' => now()->addDay(),
        ]);
        $outro->save();

        $this->post(route('estudiomusica.portal.versoes.comentar', ['token' => 'outro-token-de-teste-portal-0123456789abcdef012345', 'versao' => $this->versao->id]), [
            'tempo_segundos' => 1,
            'comentario' => 'Intruso',
        ])->assertForbidden();

        $this->assertSame(0, $this->versao->marcadores()->count());
    }
}
