<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Marcadores de Tempo / Comentários no Áudio" (secção C): o cliente
     * pausa o player num segundo específico e deixa uma nota. Sem
     * empresa_id/BelongsToTenant, à semelhança de FaturaLinha — só existe
     * através da versão de áudio a que pertence, já isolada por tenant.
     */
    public function up(): void
    {
        Schema::create('marcadores_audio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('versao_audio_id')->constrained('versoes_audio')->cascadeOnDelete();

            $table->decimal('tempo_segundos', 8, 2);
            $table->text('comentario');
            $table->boolean('criado_por_cliente')->default(true)
                ->comment('false = nota interna da equipa, deixada na mesma timeline');
            $table->string('nome_autor')->nullable();

            $table->timestamps();

            $table->index('versao_audio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcadores_audio');
    }
};
