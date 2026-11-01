<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_credito_debito_linhas', function (Blueprint $table) {
            $table->string('operation_type', 3)->nullable()->after('taxa_iva');
        });
    }

    public function down(): void
    {
        Schema::table('notas_credito_debito_linhas', function (Blueprint $table) {
            $table->dropColumn('operation_type');
        });
    }
};
