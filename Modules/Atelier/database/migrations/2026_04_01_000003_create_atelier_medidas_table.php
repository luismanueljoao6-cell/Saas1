<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de medidas: cada registo é uma FOTOGRAFIA completa das medidas
 * do cliente num dado momento — nunca se edita uma linha existente, cria-se
 * sempre uma nova (ver MedidaService::registar()). "Medidas atuais" é,
 * simplesmente, a linha mais recente por cliente_id. Isto dá o histórico
 * pedido sem precisar de uma tabela de auditoria à parte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_medidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();

            $table->decimal('busto', 6, 2)->nullable();
            $table->decimal('cintura', 6, 2)->nullable();
            $table->decimal('quadril', 6, 2)->nullable();
            $table->decimal('ombro_a_ombro', 6, 2)->nullable();
            $table->decimal('cavado', 6, 2)->nullable();
            $table->decimal('coxa', 6, 2)->nullable();
            $table->decimal('comprimento_manga', 6, 2)->nullable();
            $table->decimal('contorno_braco', 6, 2)->nullable();
            $table->decimal('comprimento_perna', 6, 2)->nullable();
            $table->decimal('tornozelo', 6, 2)->nullable();
            $table->decimal('altura', 6, 2)->nullable();

            $table->text('observacoes')->nullable();
            $table->foreignId('registado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['empresa_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_medidas');
    }
};
