<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recibos passam a ter tipo fiscal próprio: RC (por omissão, tudo o que já existe)
     * ou RG (regularização). Cada tipo tem a sua série e a sua cadeia de hash.
     */
    public function up(): void
    {
        Schema::table('recibos', function (Blueprint $table) {
            $table->string('tipo_documento', 2)->default('RC')->after('serie_id');
        });
    }

    public function down(): void
    {
        Schema::table('recibos', function (Blueprint $table) {
            $table->dropColumn('tipo_documento');
        });
    }
};
