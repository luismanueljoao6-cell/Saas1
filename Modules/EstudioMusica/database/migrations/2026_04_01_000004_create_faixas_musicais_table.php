<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faixas_musicais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('projeto_musical_id')->constrained('projetos_musicais')->cascadeOnDelete();

            $table->string('nome');
            $table->string('estado')->default('agendado');
            $table->unsignedInteger('ordem')->default(0);

            $table->timestamps();

            $table->index(['projeto_musical_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faixas_musicais');
    }
};
