<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->string('codigo')->nullable();
            $table->string('nome');
            $table->string('unidade', 20)->default('un'); // un, hora, kg, m...
            $table->decimal('preco_unitario', 14, 2);
            $table->decimal('taxa_iva', 5, 2)->nullable()
                ->comment('Nula = usa faturacao.taxa_iva_geral; permite exceções por produto (isento, taxa reduzida...)');
            $table->boolean('ativo')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produtos');
    }
};
