<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Termos de Serviço do estúdio (inclui a propriedade dos masters e dos
     * direitos de autor — Lei n.º 15/14) com VERSÕES, e o registo de quem
     * aceitou cada versão (prova do aceite: data, IP em hash, browser).
     * Ao publicar uma versão nova, os clientes voltam a ter de aceitar.
     */
    public function up(): void
    {
        Schema::create('termos_estudio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedInteger('versao');
            $table->string('titulo');
            $table->longText('conteudo');
            $table->timestamp('publicado_em');
            $table->timestamps();

            $table->unique(['empresa_id', 'versao']);
        });

        Schema::create('aceites_termos_estudio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('termos_estudio_id')->constrained('termos_estudio')->cascadeOnDelete();
            $table->timestamp('aceite_em');
            // IP guardado só em hash (não é preciso o IP em claro para provar o aceite).
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique(['cliente_id', 'termos_estudio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aceites_termos_estudio');
        Schema::dropIfExists('termos_estudio');
    }
};
