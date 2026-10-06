<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conteúdo institucional da página pública (requisito F). Uma linha por
 * empresa — 'publicado' controla se a landing page fica acessível ou
 * devolve 404 (ver Publico\LandingController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_perfis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->unique()->constrained('empresas')->cascadeOnDelete();
            $table->text('historia')->nullable();
            $table->text('especialidades')->nullable();
            $table->json('equipa')->nullable();
            $table->json('redes_sociais')->nullable();
            $table->boolean('publicado')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_perfis');
    }
};
