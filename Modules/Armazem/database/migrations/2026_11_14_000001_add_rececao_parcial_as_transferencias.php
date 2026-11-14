<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('armazem_transferencias', function (Blueprint $table): void {
            $table->string('estado', 32)->default('rascunho')->change();
        });

        Schema::table('armazem_transferencia_linhas', function (Blueprint $table): void {
            $table->decimal('quantidade_em_falta', 14, 3)->default(0)->after('quantidade_recebida');
            $table->decimal('quantidade_danificada', 14, 3)->default(0)->after('quantidade_em_falta');
        });

        Schema::create('armazem_transferencia_rececoes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('transferencia_id')->constrained('armazem_transferencias')->cascadeOnDelete();
            $table->string('chave_idempotencia', 100);
            $table->char('payload_hash', 64);
            $table->foreignId('utilizador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('finalizada')->default(false);
            $table->timestamps();

            $table->unique(['empresa_id', 'chave_idempotencia'], 'armazem_transferencia_rececoes_empresa_chave_unique');
            $table->index(['empresa_id', 'transferencia_id', 'created_at'], 'armazem_transferencia_rececoes_empresa_transferencia_idx');
        });

        Schema::create('armazem_transferencia_rececao_linhas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('rececao_id')->constrained('armazem_transferencia_rececoes')->cascadeOnDelete();
            $table->foreignId('transferencia_linha_id')
                ->constrained('armazem_transferencia_linhas', null, 'arr_rec_linhas_trans_linha_fk')
                ->restrictOnDelete();
            $table->decimal('quantidade_recebida', 14, 3)->default(0);
            $table->decimal('quantidade_em_falta', 14, 3)->default(0);
            $table->decimal('quantidade_danificada', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['rececao_id', 'transferencia_linha_id'], 'armazem_transferencia_rececao_linhas_unique');
            $table->index(['empresa_id', 'transferencia_linha_id'], 'armazem_transferencia_rececao_linhas_empresa_linha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armazem_transferencia_rececao_linhas');
        Schema::dropIfExists('armazem_transferencia_rececoes');

        Schema::table('armazem_transferencia_linhas', function (Blueprint $table): void {
            $table->dropColumn(['quantidade_em_falta', 'quantidade_danificada']);
        });
    }
};
