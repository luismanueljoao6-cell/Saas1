<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faturas passam a ter tipo fiscal próprio: FT (por omissão, tudo o que já existe)
     * ou FA (fatura de adiantamento). Cada tipo tem a sua série e a sua cadeia de hash.
     */
    public function up(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->string('tipo_documento', 2)->default('FT')->after('serie_id');
        });
    }

    public function down(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->dropColumn('tipo_documento');
        });
    }
};
