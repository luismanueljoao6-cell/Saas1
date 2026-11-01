<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fatura_linhas', function (Blueprint $table) {
            // Snapshot do tipo de operação AGT da linha; não depender de dados
            // mutáveis do produto depois de o documento ser emitido.
            $table->string('operation_type', 3)->nullable()->after('taxa_iva');
        });
    }

    public function down(): void
    {
        Schema::table('fatura_linhas', function (Blueprint $table) {
            $table->dropColumn('operation_type');
        });
    }
};
