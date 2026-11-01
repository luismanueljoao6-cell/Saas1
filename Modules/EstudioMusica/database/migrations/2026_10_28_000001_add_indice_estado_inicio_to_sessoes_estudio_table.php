<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O job de lembretes (de hora a hora, sem tenant) procura sessões por estado + janela de
     * início: sem este índice varria a tabela inteira de todas as empresas em cada execução.
     */
    public function up(): void
    {
        Schema::table('sessoes_estudio', function (Blueprint $table) {
            $table->index(['estado', 'inicio_previsto'], 'sessoes_estudio_estado_inicio_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sessoes_estudio', function (Blueprint $table) {
            $table->dropIndex('sessoes_estudio_estado_inicio_idx');
        });
    }
};
