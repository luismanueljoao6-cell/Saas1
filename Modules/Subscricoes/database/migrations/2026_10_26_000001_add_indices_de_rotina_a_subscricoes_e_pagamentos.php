<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A rotina diária e a reconciliação filtram por estado + data, sem
        // empresa_id: o índice (empresa_id, estado) existente não as serve.
        Schema::table('subscricoes', function (Blueprint $table) {
            $table->index(['estado', 'termina_em'], 'subscricoes_estado_termina_em_index');
        });

        Schema::table('pagamentos', function (Blueprint $table) {
            $table->index(['estado', 'expira_em'], 'pagamentos_estado_expira_em_index');
            $table->index(['subscricao_id', 'estado'], 'pagamentos_subscricao_id_estado_index');
        });
    }

    public function down(): void
    {
        Schema::table('subscricoes', function (Blueprint $table) {
            $table->dropIndex('subscricoes_estado_termina_em_index');
        });

        Schema::table('pagamentos', function (Blueprint $table) {
            $table->dropIndex('pagamentos_estado_expira_em_index');
            $table->dropIndex('pagamentos_subscricao_id_estado_index');
        });
    }
};
