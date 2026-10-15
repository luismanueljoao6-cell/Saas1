<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Séries autorizadas pela AGT (serviço "Solicitar Criação de Série"):
     * código atribuído pela AGT, estabelecimento, indicador de contingência e
     * intervalo de numeração autorizado. Fase 2a da adequação à AGT.
     */
    public function up(): void
    {
        if (Schema::hasColumn('series', 'agt_codigo')) {
            return;
        }

        Schema::table('series', function (Blueprint $table) {
            $table->string('agt_codigo', 60)->nullable()->after('prefixo');
            $table->string('estabelecimento', 200)->nullable()->after('agt_codigo');
            $table->string('contingencia', 1)->default('N')->after('estabelecimento');
            $table->unsignedBigInteger('quantidade_autorizada')->nullable()->after('ultimo_numero');
            $table->unsignedBigInteger('ultimo_numero_autorizado')->nullable()->after('quantidade_autorizada');
            $table->timestamp('agt_criada_em')->nullable()->after('ultimo_numero_autorizado');

            // O código de série é único por contribuinte (regra E31 da AGT).
            $table->unique(['empresa_id', 'agt_codigo']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('series', 'agt_codigo')) {
            return;
        }

        Schema::table('series', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'agt_codigo']);
            $table->dropColumn([
                'agt_codigo', 'estabelecimento', 'contingencia',
                'quantidade_autorizada', 'ultimo_numero_autorizado', 'agt_criada_em',
            ]);
        });
    }
};
