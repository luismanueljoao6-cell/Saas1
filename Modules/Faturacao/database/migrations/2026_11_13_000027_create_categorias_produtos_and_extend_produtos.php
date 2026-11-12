<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nome', 120);
            $table->string('descricao', 500)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'nome']);
        });

        Schema::table('produtos', function (Blueprint $table) {
            $table->foreignId('categoria_id')->nullable()->after('empresa_id')
                ->constrained('categorias_produtos')->nullOnDelete();
            $table->string('codigo_barras', 14)->nullable()->after('codigo');
            $table->unique(['empresa_id', 'codigo_barras'], 'produtos_empresa_codigo_barras_unique');
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropUnique('produtos_empresa_codigo_barras_unique');
            $table->dropConstrainedForeignId('categoria_id');
            $table->dropColumn('codigo_barras');
        });

        Schema::dropIfExists('categorias_produtos');
    }
};
