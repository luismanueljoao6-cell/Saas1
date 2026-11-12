<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('fatura_id')->nullable()->constrained('faturas')->nullOnDelete();
            $table->string('numero', 40);
            $table->enum('estado', ['rascunho', 'enviado', 'aceite', 'recusado', 'expirado', 'convertido'])->default('rascunho');
            $table->date('data_validade')->nullable();
            $table->json('linhas');
            $table->decimal('valor_sem_iva', 14, 2)->default(0);
            $table->decimal('valor_iva', 14, 2)->default(0);
            $table->decimal('valor_total', 14, 2)->default(0);
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamentos');
    }
};
