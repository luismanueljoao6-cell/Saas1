<?php

namespace Modules\EstudioMusica\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\EstudioMusica\Models\VersaoAudio;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Gera (uma vez) a pré-visualização de uma versão de áudio: mono, baixa taxa
 * de bits. É o que o portal serve ENQUANTO o projeto não está quitado — antes,
 * o player recebia o ficheiro original completo, e o "download só após
 * quitação" era contornável (bastava guardar o áudio a partir do player).
 * Requer `ffmpeg` no servidor (binário configurável em ESTUDIOMUSICA_FFMPEG_BIN).
 *
 * Normalmente gerada logo após o upload (Jobs\GerarPreviewAudioJob); o portal
 * continua a chamar garantir() como rede de segurança. Um lock por versão
 * garante UM só ffmpeg de cada vez (o <audio> faz vários pedidos Range).
 */
class PreviewAudioService
{
    /**
     * @return string|null caminho relativo no disco 'local', ou null se não foi possível gerar
     */
    public function garantir(VersaoAudio $versao): ?string
    {
        $disco = Storage::disk('local');

        if ($this->previewPronto($versao, $disco)) {
            return $versao->caminho_preview;
        }

        if (! $disco->exists($versao->caminho_ficheiro)) {
            return null;
        }

        try {
            return Cache::lock("estudiomusica:preview:{$versao->id}", 200)->block(25, function () use ($versao, $disco): ?string {
                // Outro processo pode ter acabado a pré-visualização enquanto esperávamos pelo lock.
                $versao->refresh();

                if ($this->previewPronto($versao, $disco)) {
                    return $versao->caminho_preview;
                }

                return $this->gerar($versao, $disco);
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    protected function previewPronto(VersaoAudio $versao, FilesystemAdapter $disco): bool
    {
        return $versao->caminho_preview && $disco->exists($versao->caminho_preview);
    }

    protected function gerar(VersaoAudio $versao, FilesystemAdapter $disco): ?string
    {
        $destino = "estudiomusica/previews/{$versao->empresa_id}/{$versao->id}.mp3";
        $temporario = "estudiomusica/previews/{$versao->empresa_id}/{$versao->id}-".bin2hex(random_bytes(4)).'.tmp.mp3';

        $disco->makeDirectory(dirname($destino));

        try {
            // Ficheiro temporário + move: nunca se escreve a meio no destino final.
            $processo = new Process([
                config('estudiomusica.ffmpeg_bin', 'ffmpeg'),
                '-y', '-v', 'error',
                '-i', $disco->path($versao->caminho_ficheiro),
                '-vn', '-ac', '1', '-ar', '22050', '-b:a', '48k',
                '-f', 'mp3',
                $disco->path($temporario),
            ]);
            $processo->setTimeout(180);
            $processo->run();

            if (! $processo->isSuccessful() || ! $disco->exists($temporario)) {
                Log::error('EstudioMusica: ffmpeg falhou ao gerar a pré-visualização', [
                    'versao_id' => $versao->id,
                    'erro' => $processo->getErrorOutput(),
                ]);
                $disco->delete($temporario);

                return null;
            }

            $disco->move($temporario, $destino);
        } catch (Throwable $e) {
            Log::error('EstudioMusica: erro ao gerar a pré-visualização', [
                'versao_id' => $versao->id,
                'erro' => $e->getMessage(),
            ]);
            $disco->delete($temporario);

            return null;
        }

        $versao->forceFill(['caminho_preview' => $destino])->save();

        return $destino;
    }
}
