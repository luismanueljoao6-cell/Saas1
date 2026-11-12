<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\MarcadorAudio;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\EstudioMusica\Tests\Support\CriaDocumentosFiscais;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

require_once __DIR__.'/../Support/CriaDocumentosFiscais.php';

/**
 * Rotas do portal que levam {token} E {versao}: o Laravel passa os
 * parâmetros de rota por POSIÇÃO, por isso cada método do controller tem de
 * declarar $token antes de $versao. Antes, reproduzir/descarregar/comentar/
 * aprovar/ajustes davam TypeError (HTTP 500) — só o aprovar tinha teste.
 */
class PortalRotasHttpTest extends TestCase
{
    use CriaDocumentosFiscais;
    use RefreshDatabase;

    protected const TOKEN = 'token-http-portal-0123456789abcdef0123456789abcd';

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected ProjetoMusical $projeto;

    protected VersaoAudio $versao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio HTTP', 'nif' => '400000021']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->cliente = (new Cliente)->forceFill([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Artista HTTP',
            'token_portal' => hash('sha256', self::TOKEN),
            'token_portal_expira_em' => now()->addDay(),
        ]);
        $this->cliente->save();

        $this->projeto = ProjetoMusical::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'nome' => 'Projeto HTTP',
            'tipo_cobranca' => 'hora',
        ]);

        $faixa = FaixaMusical::create([
            'empresa_id' => $this->empresa->id,
            'projeto_musical_id' => $this->projeto->id,
            'nome' => 'Faixa HTTP',
            'ordem' => 1,
        ]);

        Storage::disk('local')->put('estudiomusica/versoes-audio/original.mp3', 'ORIGINAL-AUDIO');

        $this->versao = VersaoAudio::create([
            'empresa_id' => $this->empresa->id,
            'faixa_musical_id' => $faixa->id,
            'rotulo' => 'Mix v1',
            'caminho_ficheiro' => 'estudiomusica/versoes-audio/original.mp3',
            'estado' => 'pendente_aprovacao',
            'enviada_em' => now(),
        ]);
    }

    protected function rota(string $nome): string
    {
        return route("estudiomusica.portal.{$nome}", ['token' => self::TOKEN, 'versao' => $this->versao->id]);
    }

    protected function paginaDoPortal(): string
    {
        return route('estudiomusica.portal.mostrar', ['token' => self::TOKEN]);
    }

    protected function quitarProjeto(): void
    {
        $fatura = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 30000);
        $this->projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);
        $this->projeto->recibos()->attach($this->reciboEmitido($fatura, 30000)->id);
    }

    protected function aceitarTermos(): void
    {
        $this->post(route('estudiomusica.portal.termos.aceitar', ['token' => self::TOKEN]))->assertRedirect();
    }

    public function test_reproduzir_nao_rebenta_com_o_token_na_rota(): void
    {
        // Sem ffmpeg/pré-visualização o portal responde 503 limpo — nunca 500 (TypeError).
        $this->get($this->rota('versoes.reproduzir'))->assertStatus(503);
    }

    public function test_contexto_tenant_do_portal_e_limpo_apos_a_resposta(): void
    {
        $this->get($this->paginaDoPortal())->assertOk();

        $this->assertFalse(app(TenantManager::class)->has());
    }

    public function test_comentar_cria_o_marcador_e_volta_ao_portal(): void
    {
        $this->post($this->rota('versoes.comentar'), ['tempo_segundos' => 83.45, 'comentario' => 'Mais graves aqui'])
            ->assertRedirect($this->paginaDoPortal())
            ->assertSessionHas('sucesso');

        $marcador = MarcadorAudio::query()->sole();

        $this->assertSame('Mais graves aqui', $marcador->comentario);
        $this->assertSame('01:23', $marcador->tempoFormatado());
    }

    public function test_solicitar_ajustes_funciona(): void
    {
        $this->post($this->rota('versoes.ajustes'))
            ->assertRedirect($this->paginaDoPortal())
            ->assertSessionHas('sucesso');

        $this->assertSame('ajustes_solicitados', $this->versao->fresh()->estado);
    }

    public function test_descarregar_projeto_por_quitar_volta_ao_portal_com_erro(): void
    {
        $this->get($this->rota('versoes.descarregar'))
            ->assertRedirect($this->paginaDoPortal())
            ->assertSessionHas('erro');
    }

    public function test_depois_de_ouvir_o_audio_as_respostas_continuam_a_voltar_ao_portal(): void
    {
        // O portal envia Referrer-Policy: no-referrer, por isso o POST não leva Referer e o
        // back() cai no "último GET" da sessão — que, depois de tocar o áudio, é o próprio
        // ficheiro de áudio. O cliente era redirecionado para o stream em vez de para o portal.
        $this->get($this->paginaDoPortal())->assertOk();
        $this->get($this->rota('versoes.reproduzir'));

        $this->post($this->rota('versoes.comentar'), ['tempo_segundos' => 10, 'comentario' => 'Ok'])
            ->assertRedirect($this->paginaDoPortal());

        $this->post(route('estudiomusica.portal.termos.aceitar', ['token' => self::TOKEN]))
            ->assertRedirect($this->paginaDoPortal());
    }

    public function test_original_so_e_servido_a_projeto_quitado_com_termos_aceites(): void
    {
        $this->quitarProjeto();

        // Quitado mas sem aceitar os termos: o player não pode receber o original
        // (bastava "guardar áudio como..." para contornar o aceite dos termos).
        $this->get($this->rota('versoes.reproduzir'))->assertStatus(503);

        $this->aceitarTermos();

        $resposta = $this->get($this->rota('versoes.reproduzir'))->assertOk();

        $this->assertSame('ORIGINAL-AUDIO', file_get_contents($resposta->baseResponse->getFile()->getPathname()));
    }

    public function test_ficheiro_original_em_falta_responde_404_e_nao_500(): void
    {
        $this->quitarProjeto();
        $this->aceitarTermos();
        Storage::disk('local')->delete('estudiomusica/versoes-audio/original.mp3');

        $this->get($this->rota('versoes.descarregar'))->assertNotFound();
        $this->get($this->rota('versoes.reproduzir'))->assertNotFound();
    }
}
