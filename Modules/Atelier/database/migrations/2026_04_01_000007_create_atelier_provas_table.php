<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_provas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('pedido_id')->constrained('atelier_pedidos')->cascadeOnDelete();

            $table->enum('tipo', ['primeira', 'segunda', 'entrega']);
            $table->dateTime('data_hora_agendada');
            $table->dateTime('data_hora_proposta_cliente')->nullable();
            $table->enum('estado', ['agendada', 'confirmada', 'reagendada', 'concluida', 'falta'])->default('agendada');
            $table->timestamp('lembrete_enviado_em')->nullable();
            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index(['empresa_id', 'data_hora_agendada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_provas');
    }
};
