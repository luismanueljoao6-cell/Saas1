<?php

namespace Modules\EstudioMusica\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\EstudioMusica\Models\VersaoAudio;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Gera (uma vez, de forma preguiçosa) a pré-visualização de uma versão de
 * áudio: mono, baixa taxa de bits. É o que o portal serve ENQUANTO o
 * projeto não está quitado — antes, o player recebia o ficheiro original
 * completo, e o "download só após quitação" era contornável (bastava
 * guardar o áudio a partir do player). Requer `ffmpeg` no servidor
 * (binário configurável em ESTUDIOMUSICA_FFMPEG_BIN).
 */
class PreviewAudioService
{
    /**
     * @return string|null caminho relativo no disco 'local', ou null se não foi possível gerar
     */
    public function garantir(VersaoAudio $versao): ?string
    {
        $disco = Storage::disk('local');

        if ($versao->caminho_preview && $disco->exists($versao->caminho_preview)) {
            return $versao->caminho_preview;
        }

        if (! $disco->exists($versao->caminho_ficheiro)) {
            return null;
        }

        $destino = "estudiomusica/previews/{$versao->empresa_id}/{$versao->id}.mp3";
        $temporario = "estudiomusica/previews/{$versao->empresa_id}/{$versao->id}-".bin2hex(random_bytes(4)).'.tmp.mp3';

        $disco->makeDirectory(dirname($destino));

        try {
            // Ficheiro temporário + move: dois pedidos simultâneos (o <audio>
            // faz vários pedidos Range) nunca escrevem no mesmo ficheiro.
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
