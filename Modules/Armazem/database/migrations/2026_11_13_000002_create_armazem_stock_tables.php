<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('armazem_stock_saldos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('armazem_id')->constrained('armazens')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->decimal('quantidade', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'armazem_id', 'produto_id'],
                'armazem_stock_saldos_empresa_armazem_produto_unique'
            );
            $table->index(['empresa_id', 'produto_id'], 'armazem_stock_saldos_empresa_produto_idx');
        });

        Schema::create('armazem_stock_movimentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('armazem_id')->constrained('armazens')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->foreignId('utilizador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 20);
            $table->decimal('quantidade', 14, 3)->comment('Variação assinada do saldo.');
            $table->decimal('saldo_anterior', 14, 3);
            $table->decimal('saldo_posterior', 14, 3);
            $table->string('motivo', 255);
            $table->string('chave_idempotencia', 100)->nullable();
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'chave_idempotencia'],
                'armazem_stock_movimentos_empresa_idem_unique'
            );
            $table->index(
                ['empresa_id', 'armazem_id', 'produto_id', 'created_at'],
                'armazem_stock_movimentos_consulta_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armazem_stock_movimentos');
        Schema::dropIfExists('armazem_stock_saldos');
    }
};
