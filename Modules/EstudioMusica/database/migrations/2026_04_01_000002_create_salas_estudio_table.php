<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de salas do estúdio (Sala A - Captação, Sala B - Mix/Master,
     * Cabine de Voz, etc.) — gerido pelo Administrador, tal como o módulo
     * de Faturação faz com produtos/serviços (mesma forma: tabela simples
     * tenant-scoped, sem histórico de alterações de preço).
     */
    public function up(): void
    {
        Schema::create('salas_estudio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->decimal('preco_hora', 10, 2)->default(0);
            $table->boolean('ativa')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['empresa_id', 'ativa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salas_estudio');
    }
};
