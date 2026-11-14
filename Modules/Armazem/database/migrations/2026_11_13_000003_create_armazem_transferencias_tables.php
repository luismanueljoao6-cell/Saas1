<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('armazem_transferencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('armazem_origem_id')->constrained('armazens')->restrictOnDelete();
            $table->foreignId('armazem_destino_id')->constrained('armazens')->restrictOnDelete();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('expedido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recebido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 20)->default('rascunho');
            $table->string('motivo', 255);
            $table->text('observacoes')->nullable();
            $table->timestamp('expedido_em')->nullable();
            $table->timestamp('recebido_em')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'estado', 'created_at'], 'armazem_transferencias_empresa_estado_idx');
            $table->index(['empresa_id', 'armazem_origem_id'], 'armazem_transferencias_origem_idx');
            $table->index(['empresa_id', 'armazem_destino_id'], 'armazem_transferencias_destino_idx');
        });

        Schema::create('armazem_transferencia_linhas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transferencia_id')->constrained('armazem_transferencias')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->decimal('quantidade', 14, 3);
            $table->decimal('quantidade_recebida', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['transferencia_id', 'produto_id'], 'armazem_transferencia_linhas_transferencia_produto_unique');
            $table->index(['empresa_id', 'produto_id'], 'armazem_transferencia_linhas_empresa_produto_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armazem_transferencia_linhas');
        Schema::dropIfExists('armazem_transferencias');
    }
};
