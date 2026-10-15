<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado do envio de cada documento à AGT. Vive numa tabela própria porque
     * os documentos emitidos são imutáveis (triggers) e não podem guardar o
     * progresso do registo/validação. Fase 2b da adequação à AGT.
     */
    public function up(): void
    {
        Schema::create('agt_submissoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('documento_type');
            $table->unsignedBigInteger('documento_id');
            $table->string('documento_no', 60);

            // pendente | enviado | valido | invalido | rejeitado | erro
            $table->string('estado', 20)->default('pendente');
            $table->string('submission_uuid', 36)->nullable();
            $table->string('request_id', 15)->nullable();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->json('erros')->nullable();

            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('ultima_consulta_em')->nullable();
            $table->timestamp('validado_em')->nullable();
            $table->timestamps();

            $table->unique(['documento_type', 'documento_id']);
            $table->index(['empresa_id', 'estado']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agt_submissoes');
    }
};
