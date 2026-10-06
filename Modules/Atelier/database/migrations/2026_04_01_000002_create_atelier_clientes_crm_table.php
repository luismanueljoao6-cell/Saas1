<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dados de CRM (aniversário, opt-in de marketing) sobre um Cliente da
 * Faturação, guardados AQUI e não na tabela `clientes` do módulo Faturacao,
 * de propósito: o Atelier não deve alterar o schema de outro módulo só
 * para acrescentar campos que só a ele interessam. Uma linha por cliente
 * (1-para-1), criada lazily na primeira vez que o cliente é editado a
 * partir do Atelier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_clientes_crm', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->unique()->constrained('clientes')->cascadeOnDelete();
            $table->date('data_nascimento')->nullable();
            $table->boolean('aceita_marketing')->default(true);
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_clientes_crm');
    }
};
