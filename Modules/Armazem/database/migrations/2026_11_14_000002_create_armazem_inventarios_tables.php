<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('armazem_inventarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('armazem_id')->constrained('armazens')->restrictOnDelete();
            $table->foreignId('aberto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('concluido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 24)->default('em_contagem');
            $table->string('observacoes', 2000)->nullable();
            $table->timestamp('aberto_em');
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'armazem_id', 'estado'], 'armazem_inventarios_empresa_armazem_estado_idx');
        });

        Schema::create('armazem_inventario_linhas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('inventario_id')->constrained('armazem_inventarios')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->decimal('quantidade_sistema', 14, 3);
            $table->decimal('quantidade_contada', 14, 3)->nullable();
            $table->decimal('diferenca', 14, 3)->nullable();
            $table->timestamps();

            $table->unique(['inventario_id', 'produto_id'], 'armazem_inventario_linhas_inventario_produto_unique');
            $table->index(['empresa_id', 'produto_id'], 'armazem_inventario_linhas_empresa_produto_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armazem_inventario_linhas');
        Schema::dropIfExists('armazem_inventarios');
    }
};
