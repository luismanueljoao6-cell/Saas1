<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturacao_chaves_publicas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresa_id');
            $table->unsignedInteger('versao');
            $table->longText('chave_publica_pem');
            $table->char('fingerprint', 64);
            $table->timestamps();

            $table->unique(['empresa_id', 'versao'], 'fat_chaves_empresa_versao_uq');
            $table->index('fingerprint', 'fat_chaves_fingerprint_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturacao_chaves_publicas');
    }
};
