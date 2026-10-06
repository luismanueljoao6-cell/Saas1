<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sem empresa_id de propósito — mesmo raciocínio que FaturaLinha: uma foto
 * só existe através do Pedido a que pertence, o isolamento por tenant já
 * está garantido ao nível do Pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_pedido_fotos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('atelier_pedidos')->cascadeOnDelete();
            $table->string('caminho');
            $table->string('legenda')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_pedido_fotos');
    }
};
