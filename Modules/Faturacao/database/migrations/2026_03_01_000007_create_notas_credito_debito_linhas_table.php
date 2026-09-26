<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_credito_debito_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nota_credito_debito_id')->constrained('notas_credito_debito')->cascadeOnDelete();

            $table->string('descricao');
            $table->decimal('quantidade', 12, 3)->default(1);
            $table->decimal('preco_unitario', 14, 2);
            $table->decimal('taxa_iva', 5, 2);

            $table->decimal('valor_sem_iva', 14, 2);
            $table->decimal('valor_iva', 14, 2);
            $table->decimal('valor_total', 14, 2);

            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_credito_debito_linhas');
    }
};
