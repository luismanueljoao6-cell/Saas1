<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('municipio', 100)->nullable()->after('morada');
            $table->string('provincia', 100)->nullable()->after('municipio');
        });

        Schema::table('produtos', function (Blueprint $table): void {
            $table->string('tipo_saft', 1)->nullable()->after('codigo');
        });

        Schema::table('fatura_linhas', function (Blueprint $table): void {
            $table->string('codigo_isencao_iva', 3)->nullable()->after('taxa_iva');
            $table->string('motivo_isencao_iva', 255)->nullable()->after('codigo_isencao_iva');
        });

        Schema::table('notas_credito_debito_linhas', function (Blueprint $table): void {
            $table->string('codigo_isencao_iva', 3)->nullable()->after('taxa_iva');
            $table->string('motivo_isencao_iva', 255)->nullable()->after('codigo_isencao_iva');
        });
    }

    public function down(): void
    {
        Schema::table('notas_credito_debito_linhas', function (Blueprint $table): void {
            $table->dropColumn(['codigo_isencao_iva', 'motivo_isencao_iva']);
        });
        Schema::table('fatura_linhas', function (Blueprint $table): void {
            $table->dropColumn(['codigo_isencao_iva', 'motivo_isencao_iva']);
        });
        Schema::table('produtos', function (Blueprint $table): void {
            $table->dropColumn('tipo_saft');
        });
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn(['municipio', 'provincia']);
        });
    }
};
