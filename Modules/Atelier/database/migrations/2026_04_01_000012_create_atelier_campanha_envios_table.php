<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Um envio por (campanha, cliente) — a unique constraint é o que torna
 * DispararCampanhaJob seguro de correr mais de uma vez sem duplicar
 * mensagens (o mesmo cuidado de idempotência que PagamentoService já
 * aplica aos webhooks de pagamento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_campanha_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campanha_id')->constrained('atelier_campanhas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->string('canal');
            $table->enum('estado', ['pendente', 'enviado', 'falhou'])->default('pendente');
            $table->text('erro')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();

            $table->unique(['campanha_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_campanha_envios');
    }
};
