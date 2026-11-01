<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
use Modules\EstudioMusica\Jobs\GerarPreviewAudioJob;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\MarcadorAudio;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\EstudioMusica\Services\PreviewAudioService;
use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

require_once __DIR__.'/../Support/ComEstudioAutenticado.php';

/** Versões de áudio vistas pela equipa: upload, audição interna, lembrete e pré-visualização. */
class VersaoAudioStaffTest extends TestCase
{
    use ComEstudioAutenticado;
    use RefreshDatabase;

    protected User $admin;

    protected FaixaMusical $faixa;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = $this->registarEstudio('510000101');
        $this->noTenantDe($this->admin);

        $cliente = Cliente::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Artista Staff', 'email' => 'artista@example.test']);
        $projeto = ProjetoMusical::create([
            'empresa_id' => $this->admin->empresa_id,
            'cliente_id' => $cliente->id,
            'nome' => 'Projeto Staff',
            'tipo_cobranca' => 'hora',
        ]);
        $this->faixa = FaixaMusical::create([
            'empresa_id' => $this->admin->empresa_id,
            'projeto_musical_id' => $projeto->id,
            'nome' => 'Faixa Staff',
            'ordem' => 1,
        ]);
    }

    protected function versao(string $rotulo, string $estado, int $minutos, string $caminho = 'estudiomusica/versoes-audio/x.mp3'): VersaoAudio
    {
        return VersaoAudio::create([
            'empresa_id' => $this->admin->empresa_id,
            'faixa_musical_id' => $this->faixa->id,
            'rotulo' => $rotulo,
            'caminho_ficheiro' => $caminho,
            'estado' => $estado,
            'enviada_em' => now()->addMinutes($minutos),
        ]);
    }

    public function test_upload_guarda_a_versao_e_pede_a_pre_visualizacao_em_segundo_plano(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.faixas.versoes.store', $this->faixa), [
                'rotulo' => 'Mix v1',
                'ficheiro' => UploadedFile::fake()->create('mix.mp3', 100, 'audio/mpeg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('sucesso');

        $this->noTenantDe($this->admin);
        $versao = VersaoAudio::query()->sole();

        Queue::assertPushed(GerarPreviewAudioJob::class, fn (GerarPreviewAudioJob $job) => $job->versaoId === $versao->id
            && $job->empresaId === (int) $this->admin->empresa_id);
        Storage::disk('local')->assertExists($versao->caminho_ficheiro);
    }

    public function test_audicao_interna_com_ficheiro_em_falta_responde_404(): void
    {
        $versao = $this->versao('Mix v1', 'pendente_aprovacao', 0, 'estudiomusica/versoes-audio/nao-existe.mp3');

        $this->actingAs($this->admin)
            ->get(route('estudiomusica.versoes.reproduzir', $versao))
            ->assertNotFound();
    }

    public function test_lembrete_so_vai_para_a_versao_mais_recente_que_ainda_aguarda_decisao(): void
    {
        Queue::fake();

        $antiga = $this->versao('Mix v1', 'pendente_aprovacao', 0);
        $recente = $this->versao('Mix v2', 'pendente_aprovacao', 5);

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.versoes.lembrete-aprovacao', $antiga))
            ->assertSessionHas('erro');
        Queue::assertNotPushed(EnviarMensagemClienteJob::class);

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.versoes.lembrete-aprovacao', $recente))
            ->assertSessionHas('sucesso');
        Queue::assertPushed(EnviarMensagemClienteJob::class, 1);

        $this->noTenantDe($this->admin);
        $recente->update(['estado' => 'aprovada']);

        $this->actingAs($this->admin)
            ->post(route('estudiomusica.versoes.lembrete-aprovacao', $recente))
            ->assertSessionHas('erro');
        Queue::assertPushed(EnviarMensagemClienteJob::class, 1);
    }

    public function test_marcador_formata_o_tempo_sem_avisos_de_depreciacao(): void
    {
        $avisos = [];
        set_error_handler(function (int $nivel, string $mensagem) use (&$avisos): bool {
            $avisos[] = $mensagem;

            return true;
        });

        try {
            $textos = array_map(
                fn (string $segundos) => (new MarcadorAudio(['tempo_segundos' => $segundos]))->tempoFormatado(),
                ['0', '5.9', '83.45', '3599.99', '3600'],
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $avisos);
        $this->assertSame(['00:00', '00:05', '01:23', '59:59', '60:00'], $textos);
    }

    public function test_pre_visualizacao_e_gerada_uma_vez_e_guardada_na_versao(): void
    {
        $ffmpeg = sys_get_temp_dir().'/ffmpeg-falso-'.bin2hex(random_bytes(4)).'.sh';
        file_put_contents($ffmpeg, "#!/bin/sh\nfor ultimo; do :; done\nprintf 'PREVIEW' > \"\$ultimo\"\n");
        chmod($ffmpeg, 0755);
        config(['estudiomusica.ffmpeg_bin' => $ffmpeg]);

        $versao = $this->versao('Mix v1', 'pendente_aprovacao', 0);
        Storage::disk('local')->put($versao->caminho_ficheiro, 'ORIGINAL');

        (new GerarPreviewAudioJob($versao->id, (int) $versao->empresa_id))
            ->handle(app(TenantManager::class), app(PreviewAudioService::class));

        @unlink($ffmpeg);
        $this->noTenantDe($this->admin);
        $versao->refresh();

        $this->assertSame("estudiomusica/previews/{$versao->empresa_id}/{$versao->id}.mp3", $versao->caminho_preview);
        $this->assertSame('PREVIEW', Storage::disk('local')->get($versao->caminho_preview));
        // Só o ficheiro final: nenhum temporário esquecido.
        $this->assertCount(1, Storage::disk('local')->allFiles('estudiomusica/previews'));
    }

    public function test_sem_ffmpeg_a_pre_visualizacao_falha_em_silencio_e_sem_lixo(): void
    {
        config(['estudiomusica.ffmpeg_bin' => '/nao/existe/ffmpeg']);

        $versao = $this->versao('Mix v1', 'pendente_aprovacao', 0);
        Storage::disk('local')->put($versao->caminho_ficheiro, 'ORIGINAL');

        $this->assertNull(app(PreviewAudioService::class)->garantir($versao));
        $this->assertNull($versao->fresh()->caminho_preview);
        $this->assertSame([], Storage::disk('local')->allFiles('estudiomusica/previews'));
    }
}
