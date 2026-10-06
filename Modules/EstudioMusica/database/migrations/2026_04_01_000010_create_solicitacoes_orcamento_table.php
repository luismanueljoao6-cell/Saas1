<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formulário público "Solicitar Orçamento / Reservar Horário" da
     * landing page (secção G). Fica como um lead simples — a Receção
     * confirma disponibilidade (o público não vê conflitos de agenda) e
     * converte manualmente num Cliente + SessaoEstudio reais.
     */
    public function up(): void
    {
        Schema::create('solicitacoes_orcamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->string('nome');
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->string('tipo_servico_desejado')->nullable();
            $table->date('data_preferida')->nullable();
            $table->text('mensagem')->nullable();

            $table->enum('estado', ['pendente', 'contactado', 'convertido', 'descartado'])->default('pendente');

            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitacoes_orcamento');
    }
};
