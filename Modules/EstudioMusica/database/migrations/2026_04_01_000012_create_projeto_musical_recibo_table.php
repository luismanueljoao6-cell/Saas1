<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projeto_musical_recibo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_musical_id')->constrained('projetos_musicais')->cascadeOnDelete();
            $table->foreignId('recibo_id')->constrained('recibos')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['projeto_musical_id', 'recibo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projeto_musical_recibo');
    }
};
