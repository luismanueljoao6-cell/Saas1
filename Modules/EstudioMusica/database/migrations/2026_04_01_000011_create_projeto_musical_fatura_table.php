<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela pivot de propriedade do EstudioMusica — de propósito NÃO é
     * uma coluna projeto_musical_id em `faturas` (isso obrigaria o módulo
     * Faturacao a saber que o EstudioMusica existe, invertendo a direção
     * de dependência que o resto do sistema respeita: módulos de negócio
     * podem depender de Faturacao, nunca o contrário).
     */
    public function up(): void
    {
        Schema::create('projeto_musical_fatura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_musical_id')->constrained('projetos_musicais')->cascadeOnDelete();
            $table->foreignId('fatura_id')->constrained('faturas')->cascadeOnDelete();
            $table->enum('tipo', ['sinal', 'saldo_final', 'avulso'])->default('avulso');

            $table->timestamps();

            $table->unique(['projeto_musical_id', 'fatura_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projeto_musical_fatura');
    }
};
