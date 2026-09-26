<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('subscricao_id')->constrained('subscricoes')->cascadeOnDelete();

            $table->string('gateway'); // 'proxypay', 'manual', ...
            $table->string('referencia_externa')->nullable()->unique()
                ->comment('Nº de referência devolvido pelo gateway (ex.: entidade+referência ProxyPay)');

            $table->decimal('valor', 12, 2);
            $table->string('moeda', 3)->default('AOA');
            $table->enum('estado', ['pendente', 'confirmado', 'falhado', 'expirado'])->default('pendente');

            // Guarda o payload bruto devolvido pelo gateway/webhook, para
            // auditoria e para depuração sem depender de logs externos.
            $table->json('payload_bruto')->nullable();

            $table->timestamp('expira_em')->nullable();
            $table->timestamp('confirmado_em')->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Preenchido só em confirmação manual (ver PagamentoService::confirmarManualmente)');

            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos');
    }
};
