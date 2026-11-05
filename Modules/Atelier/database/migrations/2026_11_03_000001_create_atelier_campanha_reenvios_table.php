<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_campanha_reenvios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('campanha_id')->constrained('atelier_campanhas')->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->string('estado', 20)->default('aceite');
            // true enquanto o pedido está aceite/iniciado; NULL quando fecha.
            // A unique (campanha_id, aberto) permite no máximo UM pedido aberto
            // por campanha, e vários fechados (NULL não colide em MySQL/SQLite).
            $table->boolean('aberto')->nullable()->default(true);
            $table->foreignId('solicitado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('solicitado_em');
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->timestamps();

            $table->unique(['campanha_id', 'aberto'], 'atelier_campanha_reenvio_aberto_unique');
            $table->index(['empresa_id', 'campanha_id'], 'atelier_campanha_reenvio_empresa_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_campanha_reenvios');
    }
};
