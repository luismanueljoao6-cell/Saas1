<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Um único model (NotaCreditoDebito, com coluna 'tipo') para nota de
     * crédito e nota de débito — a estrutura é idêntica, só o sentido do
     * ajuste (a favor do cliente ou da empresa) muda. Mantém a cadeia de
     * hash desta tabela SEPARADA da de faturas: são séries diferentes
     * (NC/ND), nunca a mesma cadeia.
     */
    public function up(): void
    {
        Schema::create('notas_credito_debito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('serie_id')->constrained('series');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('fatura_id')->nullable()->constrained('faturas')
                ->comment('Fatura original corrigida por este documento, quando aplicável');

            $table->enum('tipo', ['credito', 'debito']);
            $table->string('motivo');

            $table->unsignedBigInteger('numero_sequencial')->nullable();
            $table->string('numero_documento')->nullable();
            $table->date('data_emissao')->nullable();
            $table->timestamp('data_hora_sistema')->nullable();

            $table->enum('estado', ['rascunho', 'emitida', 'anulada'])->default('rascunho');

            $table->decimal('valor_sem_iva', 14, 2)->default(0);
            $table->decimal('valor_iva', 14, 2)->default(0);
            $table->decimal('valor_total', 14, 2)->default(0);
            $table->string('moeda', 3)->default('AOA');

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
        Schema::dropIfExists('notas_credito_debito');
    }
};
