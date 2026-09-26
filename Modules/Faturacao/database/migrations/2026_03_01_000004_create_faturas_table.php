<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('serie_id')->constrained('series');
            $table->foreignId('cliente_id')->constrained('clientes');

            // Preenchidos só no momento da emissão (ver FaturaService::emitir) —
            // antes disso a fatura é um rascunho sem valor fiscal.
            $table->unsignedBigInteger('numero_sequencial')->nullable();
            $table->string('numero_documento')->nullable()
                ->comment('Ex.: "FT A2026/143" — formato legível construído a partir da série + número sequencial');

            $table->date('data_emissao')->nullable();
            $table->timestamp('data_hora_sistema')->nullable()
                ->comment('Data/hora exata do sistema no momento da emissão, ao segundo — entra na string assinada');

            $table->enum('estado', ['rascunho', 'emitida', 'anulada'])->default('rascunho')
                ->comment('anulada só é permitido ANTES de emitida; depois de emitida, corrige-se com Nota de Crédito, nunca por aqui');

            $table->decimal('valor_sem_iva', 14, 2)->default(0);
            $table->decimal('valor_iva', 14, 2)->default(0);
            $table->decimal('valor_total', 14, 2)->default(0);
            $table->string('moeda', 3)->default('AOA');

            // Campos da cadeia de assinatura fiscal (ver AssinaturaFiscalService)
            $table->text('hash')->nullable();
            $table->text('hash_anterior')->nullable()
                ->comment('Hash do documento anterior da MESMA série/tipo; vazio apenas no primeiro documento da série');
            $table->unsignedInteger('chave_versao')->nullable();

            $table->text('observacoes')->nullable();

            $table->timestamps();
            // SEM softDeletes de propósito: uma fatura emitida nunca é
            // apagada, soft ou não — ver Traits/Imutavel.

            $table->unique(['serie_id', 'numero_sequencial']);
            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturas');
    }
};
