<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registo de idempotência das automações de marketing (secção F):
     * sem isto, um job agendado que corre todos os dias enviaria a mesma
     * mensagem de aniversário ou de follow-up repetidamente. `referencia`
     * guarda o que distingue uma "edição" da campanha da outra (ex.: o
     * ano, para aniversário; o id do projeto, para follow-up de
     * lançamento) — ver CampanhaMarketingService.
     */
    public function up(): void
    {
        Schema::create('campanhas_enviadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();

            $table->string('tipo')->comment('aniversario | data_comemorativa | follow_up_lancamento');
            $table->string('referencia')->nullable();
            $table->timestamp('enviada_em');

            $table->timestamps();

            $table->unique(['empresa_id', 'cliente_id', 'tipo', 'referencia'], 'campanhas_envio_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campanhas_enviadas');
    }
};
