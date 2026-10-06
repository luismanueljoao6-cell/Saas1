<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Insumos cobrados adicionalmente (linhas, zíperes, tecido fornecido pelo
 * atelier). Mesma forma de FaturaLinha porque acaba por virar linhas dessa
 * mesma família quando a fatura final é gerada (ver PedidoFaturacaoService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_pedido_materiais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('atelier_pedidos')->cascadeOnDelete();
            $table->string('descricao');
            $table->decimal('quantidade', 10, 2)->default(1);
            $table->decimal('valor_unitario', 14, 2);
            $table->decimal('valor_total', 14, 2);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_pedido_materiais');
    }
};
