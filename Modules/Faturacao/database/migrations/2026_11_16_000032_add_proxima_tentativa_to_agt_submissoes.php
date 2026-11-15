<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Backoff por documento nas falhas transitórias de envio à AGT (Fase 5, F5-06). */
    public function up(): void
    {
        Schema::table('agt_submissoes', function (Blueprint $table) {
            $table->timestamp('proxima_tentativa_em')->nullable()->after('ultima_consulta_em');
            $table->index(['empresa_id', 'estado', 'proxima_tentativa_em'], 'agt_subm_proxima_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agt_submissoes', function (Blueprint $table) {
            $table->dropIndex('agt_subm_proxima_idx');
            $table->dropColumn('proxima_tentativa_em');
        });
    }
};
