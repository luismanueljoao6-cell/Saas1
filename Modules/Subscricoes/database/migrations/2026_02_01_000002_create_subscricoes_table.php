<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscricoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('plano_id')->constrained('planos');

            $table->enum('estado', ['pendente', 'ativa', 'cancelada', 'expirada'])->default('pendente');
            $table->timestamp('inicio_em')->nullable();
            $table->timestamp('termina_em')->nullable();
            $table->boolean('renovacao_automatica')->default(true);
            $table->timestamp('cancelada_em')->nullable();

            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscricoes');
    }
};
