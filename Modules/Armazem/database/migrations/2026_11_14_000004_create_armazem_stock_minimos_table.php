<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('armazem_stock_minimos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('armazem_id')->constrained('armazens')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->decimal('quantidade_minima', 14, 3);
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'armazem_id', 'produto_id'],
                'armazem_stock_minimos_empresa_local_produto_unique'
            );
            $table->index(
                ['empresa_id', 'quantidade_minima'],
                'armazem_stock_minimos_empresa_quantidade_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armazem_stock_minimos');
    }
};
