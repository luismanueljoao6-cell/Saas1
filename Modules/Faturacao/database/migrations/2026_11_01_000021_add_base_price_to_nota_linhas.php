<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_credito_debito_linhas', function (Blueprint $table) {
            $table->decimal('preco_unitario_base', 14, 2)->nullable()->after('preco_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('notas_credito_debito_linhas', function (Blueprint $table) {
            $table->dropColumn('preco_unitario_base');
        });
    }
};
