<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reservas de sala (secção B). A prevenção de conflitos (double
     * booking) é feita em Services\AgendamentoService com lockForUpdate
     * sobre este índice — a migration só garante que a consulta de
     * sobreposição (sala + intervalo) é rápida.
     */
    public function up(): void
    {
        Schema::create('sessoes_estudio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sala_estudio_id')->constrained('salas_estudio');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('projeto_musical_id')->nullable()
                ->constrained('projetos_musicais')->nullOnDelete()
                ->comment('Nulo para sessões avulsas sem projeto associado (ex.: locução/podcast)');
            $table->foreignId('engenheiro_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('tipo_servico')
                ->comment('Chave de estudiomusica.tipos_servico, ex.: gravacao_voz, mixagem');

            $table->dateTime('inicio_previsto');
            $table->dateTime('fim_previsto');
            $table->dateTime('inicio_real')->nullable()->comment('Check-in');
            $table->dateTime('fim_real')->nullable()->comment('Check-out');

            $table->enum('estado', ['agendada', 'confirmada', 'em_curso', 'concluida', 'cancelada'])
                ->default('agendada');

            $table->timestamp('lembrete_24h_enviado_em')->nullable();
            $table->timestamp('lembrete_2h_enviado_em')->nullable();

            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index(['sala_estudio_id', 'inicio_previsto', 'fim_previsto'], 'sessoes_sala_intervalo_idx');
            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessoes_estudio');
    }
};
