<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->boolean('administrador')->default(false);
            // Só o hash (SHA-256) do token é guardado; o token em claro vai apenas no e-mail.
            $table->string('token_hash', 64)->unique();
            $table->foreignId('convidado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('aceite_em')->nullable();
            $table->timestamp('revogado_em')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convites');
    }
};
