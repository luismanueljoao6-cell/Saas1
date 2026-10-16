<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Caixa de entrada durável: todo o webhook autenticado é guardado AQUI
        // antes de ser confirmado ao gateway, independentemente de a fila ou
        // da aplicação falharem a seguir.
        Schema::create('webhook_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 40);
            $table->char('chave_evento', 64)->unique()->comment('sha256(gateway + corpo bruto): deduplica reenvios');
            $table->string('referencia_externa')->nullable()->index();
            $table->string('valor', 20)->nullable();
            $table->longText('corpo_bruto');
            $table->string('estado', 20)->default('recebido')
                ->comment('recebido|processado|rejeitado|ilegivel|falhado|resolvido');
            $table->text('motivo_falha')->nullable();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->unsignedInteger('recebimentos')->default(1);
            $table->timestamp('processado_em')->nullable();
            $table->timestamps();

            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_eventos');
    }
};
