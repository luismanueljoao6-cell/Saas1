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
 * Portal: o cliente só aprova versões depois de aceitar os Termos de
 * Serviço (propriedade dos masters), e só decide sobre a versão mais recente.
 */
class PortalTermosTest extends TestCase
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

    public function test_portal_pede_aceite_dos_termos_e_esconde_o_pedido_depois_de_aceitar(): void
    {
        $this->get(route('estudiomusica.portal.mostrar', ['token' => self::TOKEN]))
            ->assertOk()
            ->assertSee('Li e aceito');

        $this->post(route('estudiomusica.portal.termos.aceitar', ['token' => self::TOKEN]))->assertRedirect();

        $this->get(route('estudiomusica.portal.mostrar', ['token' => self::TOKEN]))
            ->assertOk()
            ->assertDontSee('Li e aceito');
    }

    public function test_aprovar_antes_de_aceitar_os_termos_e_recusado(): void
    {
        $this->post($this->rota('versoes.aprovar'))->assertSessionHas('erro');

        $this->assertSame('pendente_aprovacao', $this->versao->fresh()->estado);
    }

    public function test_aprovar_depois_de_aceitar_os_termos_funciona(): void
    {
        $this->post(route('estudiomusica.portal.termos.aceitar', ['token' => self::TOKEN]));

        $this->post($this->rota('versoes.aprovar'))->assertSessionMissing('erro');

        $this->assertSame('aprovada', $this->versao->fresh()->estado);
    }

    public function test_so_a_versao_mais_recente_aceita_decisao(): void
    {
        $this->post(route('estudiomusica.portal.termos.aceitar', ['token' => self::TOKEN]));

        VersaoAudio::create([
            'empresa_id' => $this->empresa->id,
            'faixa_musical_id' => $this->versao->faixa_musical_id,
            'rotulo' => 'Mix v2',
            'caminho_ficheiro' => 'estudiomusica/versoes-audio/inexistente2.wav',
            'estado' => 'pendente_aprovacao',
            'enviada_em' => now()->addMinute(),
        ]);

        $this->post($this->rota('versoes.aprovar'))->assertSessionHas('erro');

        $this->assertSame('pendente_aprovacao', $this->versao->fresh()->estado);
    }

    public function test_resposta_do_portal_nao_e_cacheavel_e_nao_vaza_o_referer(): void
    {
        $resposta = $this->get(route('estudiomusica.portal.mostrar', ['token' => self::TOKEN]));

        $resposta->assertHeader('Referrer-Policy', 'no-referrer');
        $resposta->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $resposta->headers->get('Cache-Control'));
    }
}
