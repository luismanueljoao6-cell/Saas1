<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canal digital de reclamações da landing page. NÃO substitui o Livro
     * de Reclamações físico, que o Decreto Presidencial n.º 234/16 exige
     * nos estabelecimentos — é um canal adicional, com registo e resposta.
     */
    public function up(): void
    {
        Schema::create('reclamacoes_estudio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->string('nome');
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->string('assunto', 150);
            $table->text('descricao');

            $table->enum('estado', ['recebida', 'em_analise', 'resolvida'])->default('recebida');
            $table->text('resposta')->nullable();
            $table->timestamp('respondida_em')->nullable();
            $table->timestamp('consentimento_dados_em')->nullable();

            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclamacoes_estudio');
    }
};
