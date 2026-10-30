<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * As fotos dos pedidos são dados pessoais dos clientes: deixam de ficar no
 * disco 'public' (acessível por URL sem login) e passam para o disco
 * privado 'local', servido só por rota autenticada (FotoController).
 *
 * Move os ficheiros já existentes; uma falha num ficheiro é registada e não
 * impede os restantes (FotoController continua a servir do disco antigo
 * enquanto o ficheiro lá estiver).
 */
return new class extends Migration
{
    public function up(): void
    {
        $publico = Storage::disk('public');
        $privado = Storage::disk('local');

        DB::table('atelier_pedido_fotos')->orderBy('id')->each(function ($foto) use ($publico, $privado) {
            $caminho = $foto->caminho;

            try {
                if (! $publico->exists($caminho) || $privado->exists($caminho)) {
                    return;
                }

                $stream = $publico->readStream($caminho);
                $privado->writeStream($caminho, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                if ($privado->exists($caminho)) {
                    $publico->delete($caminho);
                }
            } catch (Throwable $e) {
                Log::warning('Atelier: não foi possível mover uma foto para o disco privado', [
                    'foto_id' => $foto->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Intencionalmente vazio: não voltamos a expor fotos de clientes em público.
    }
};
