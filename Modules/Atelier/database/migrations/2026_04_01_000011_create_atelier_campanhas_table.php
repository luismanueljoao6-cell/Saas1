<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_campanhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nome');
            $table->enum('canal', ['mail', 'whatsapp', 'sms'])->default('mail');
            $table->enum('segmento', ['todos', 'aniversariantes_mes', 'inativos'])->default('todos');
            $table->string('assunto')->nullable();
            $table->text('mensagem');
            $table->timestamp('agendada_para')->nullable();
            $table->timestamp('enviada_em')->nullable();
            $table->enum('estado', ['rascunho', 'agendada', 'enviada', 'cancelada'])->default('rascunho');
            $table->foreignId('criada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_campanhas');
    }
};
