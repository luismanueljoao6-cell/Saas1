<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cupões de desconto em horas de estúdio, gerados pela campanha de
     * aniversário do artista (secção F). Âmbito deliberadamente restrito
     * a essa campanha — não é um motor de cupões genérico para outras
     * partes do SaaS.
     */
    public function up(): void
    {
        Schema::create('cupons_desconto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();

            $table->string('codigo');
            $table->decimal('percentual_desconto', 5, 2);
            $table->string('motivo')->default('aniversario');
            $table->date('valido_ate');
            $table->timestamp('usado_em')->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cupons_desconto');
    }
};
