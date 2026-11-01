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
 * As rotas do portal têm DOIS parâmetros ({token} e {versao}) e o Laravel
 * passa-os aos métodos por posição: sem `$token` na assinatura, o token caía
 * em `int $versao` e o pedido rebentava com TypeError (HTTP 500). Cada teste
 * exercita uma rota do portal que recebe {versao}.
 */
class PortalRotasTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-de-teste-rotas-0123456789abcdef0123456789ab';

    protected Empresa $empresa;

    protected VersaoAudio $versao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Rotas', 'nif' => '400000016']);
        app(TenantManager::class)->set($this->empresa->id);

        $cliente = (new Cliente)->forceFill([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Artista Rotas',
            'token_portal' => hash('sha256', self::TOKEN),
            'token_portal_expira_em' => now()->addDay(),
        ]);
        $cliente->save();

        $projeto = ProjetoMusical::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $cliente->id,
            'nome' => 'Projeto Rotas',
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

    public function test_comentar_regista_o_marcador_do_cliente(): void
    {
        $this->post($this->rota('versoes.comentar'), [
            'tempo_segundos' => 83,
            'comentario' => 'Mais graves no refrão',
        ])->assertRedirect()->assertSessionHas('sucesso');

        $this->assertSame(1, $this->versao->marcadores()->count());
    }

    public function test_pedir_ajustes_muda_o_estado_da_versao(): void
    {
        $this->post($this->rota('versoes.ajustes'))
            ->assertRedirect()
            ->assertSessionHas('sucesso');

        $this->assertSame('ajustes_solicitados', $this->versao->fresh()->estado);
    }

    public function test_descarregar_antes_da_quitacao_e_recusado_com_mensagem(): void
    {
        $this->get($this->rota('versoes.descarregar'))
            ->assertRedirect()
            ->assertSessionHas('erro');
    }

    public function test_reproduzir_sem_pre_visualizacao_responde_503_e_nao_500(): void
    {
        // Sem o ficheiro original não há pré-visualização: o controller aborta
        // de propósito com 503. Antes da correção dava 500 (TypeError).
        $this->get($this->rota('versoes.reproduzir'))->assertStatus(503);
    }
}
