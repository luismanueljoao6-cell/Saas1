<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liga a sessão à fatura por hora emitida no check-out. Torna a
     * cobrança idempotente: uma sessão com fatura_id nunca volta a ser
     * faturada (ver FaturacaoEstudioService::cobrarSessao()).
     */
    public function up(): void
    {
        Schema::table('sessoes_estudio', function (Blueprint $table) {
            $table->foreignId('fatura_id')->nullable()->after('estado')
                ->constrained('faturas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sessoes_estudio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fatura_id');
        });
    }
};
