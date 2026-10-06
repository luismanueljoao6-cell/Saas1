<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versoes_audio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('faixa_musical_id')->constrained('faixas_musicais')->cascadeOnDelete();

            $table->string('rotulo')->comment('Ex.: "Mix v1", "Master Final"');
            $table->string('caminho_ficheiro');
            $table->enum('estado', ['pendente_aprovacao', 'aprovada', 'ajustes_solicitados'])
                ->default('pendente_aprovacao');
            $table->timestamp('enviada_em')->nullable();

            $table->timestamps();

            $table->index(['faixa_musical_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versoes_audio');
    }
};
