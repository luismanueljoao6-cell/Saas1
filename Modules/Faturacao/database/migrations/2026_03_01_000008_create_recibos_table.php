<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recibos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('serie_id')->constrained('series');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('fatura_id')->nullable()->constrained('faturas')
                ->comment('Nulo para recibos de adiantamento, sem fatura associada ainda');

            $table->unsignedBigInteger('numero_sequencial')->nullable();
            $table->string('numero_documento')->nullable();
            $table->date('data_emissao')->nullable();
            $table->timestamp('data_hora_sistema')->nullable();

            $table->enum('estado', ['rascunho', 'emitido', 'anulado'])->default('rascunho');

            $table->decimal('valor', 14, 2);
            $table->string('moeda', 3)->default('AOA');
            $table->enum('meio_pagamento', ['dinheiro', 'transferencia', 'multicaixa', 'outro'])->default('multicaixa');

            $table->text('hash')->nullable();
            $table->text('hash_anterior')->nullable();
            $table->unsignedInteger('chave_versao')->nullable();

            $table->timestamps();

            $table->unique(['serie_id', 'numero_sequencial']);
            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recibos');
    }
};
