<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * customerCountry (assinado em cada documento): código ISO 3166-1 alpha-2
     * do país do cliente; "AO" por omissão. Fase 1 da adequação à AGT.
     */
    public function up(): void
    {
        if (Schema::hasColumn('clientes', 'pais')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table) {
            $table->string('pais', 2)->default('AO')->after('nif');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('clientes', 'pais')) {
            Schema::table('clientes', function (Blueprint $table) {
                $table->dropColumn('pais');
            });
        }
    }
};
