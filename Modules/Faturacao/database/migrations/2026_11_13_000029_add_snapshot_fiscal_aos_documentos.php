<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->json('snapshot_fiscal')->nullable()->after('chave_versao');
        });

        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->json('snapshot_fiscal')->nullable()->after('chave_versao');
        });
    }

    public function down(): void
    {
        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->dropColumn('snapshot_fiscal');
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->dropColumn('snapshot_fiscal');
        });
    }
};
