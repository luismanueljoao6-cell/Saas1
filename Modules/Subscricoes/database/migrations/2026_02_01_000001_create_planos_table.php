<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('slug')->unique();
            $table->text('descricao')->nullable();
            $table->decimal('preco', 12, 2);
            $table->string('moeda', 3)->default('AOA');
            $table->unsignedInteger('periodo_dias')->default(30);

            // Limites/funcionalidades do plano (ex.: max_utilizadores,
            // max_faturas_mes, modulos_incluidos) — mantido flexível em vez
            // de uma coluna por limite, para não obrigar a uma migration
            // sempre que um novo limite for criado.
            $table->json('limites')->nullable();

            $table->boolean('ativo')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos');
    }
};
