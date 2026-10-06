<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_portfolio_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('categoria');
            $table->string('titulo');
            $table->string('caminho_foto');
            $table->text('descricao')->nullable();
            $table->boolean('destaque')->default(false);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();

            $table->index(['empresa_id', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_portfolio_itens');
    }
};
