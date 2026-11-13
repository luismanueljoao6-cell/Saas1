<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->boolean('controla_stock')->default(false)->after('ativo');
            $table->decimal('stock_minimo', 14, 3)->default(0)->after('controla_stock');
        });

        Schema::create('stock_saldos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->cascadeOnDelete();
            $table->decimal('quantidade', 14, 3)->default(0);
            $table->timestamps();
            $table->unique(['empresa_id', 'produto_id']);
            $table->index(['empresa_id', 'quantidade']);
        });

        Schema::create('stock_movimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->foreignId('utilizador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 20);
            $table->decimal('quantidade', 14, 3)->comment('Delta assinado; em ajuste representa a diferença entre contagem e saldo anterior.');
            $table->decimal('saldo_anterior', 14, 3);
            $table->decimal('saldo_posterior', 14, 3);
            $table->string('motivo', 255);
            $table->string('chave_idempotencia', 100)->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'chave_idempotencia'], 'stock_mov_empresa_idem_unique');
            $table->index(['empresa_id', 'produto_id', 'created_at'], 'stock_mov_empresa_produto_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movimentos');
        Schema::dropIfExists('stock_saldos');

        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn(['controla_stock', 'stock_minimo']);
        });
    }
};
