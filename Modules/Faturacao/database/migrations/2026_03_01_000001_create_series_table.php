<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma série por (empresa, tipo_documento, ano). ultimo_numero é
     * incrementado dentro de uma transação com lock (ver NumeracaoService)
     * para garantir numeração sequencial SEM LACUNAS, como a lei exige.
     */
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('tipo_documento', 2); // FT, NC, ND, RC
            $table->unsignedSmallInteger('ano');
            $table->string('prefixo')->default('A'); // ex.: "A" -> "FT A2026/1"
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->timestamps();

            $table->unique(['empresa_id', 'tipo_documento', 'ano', 'prefixo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
