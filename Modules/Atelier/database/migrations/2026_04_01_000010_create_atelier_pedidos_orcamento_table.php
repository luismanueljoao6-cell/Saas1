<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads do formulário público "Agende uma Consulta/Orçamento" (requisito
 * F.3) — submetidos por um visitante sem conta, por isso NÃO usa
 * BelongsToTenant da forma normal (não há tenant autenticado). O
 * empresa_id vem explícito do slug da própria landing page; a trait
 * respeita esse valor exatamente porque nenhum tenant está definido no
 * momento (ver BelongsToTenant, ramo "sem tenant identificado").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_pedidos_orcamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nome');
            $table->string('contacto');
            $table->string('categoria_interesse')->nullable();
            $table->text('mensagem')->nullable();
            $table->enum('estado', ['novo', 'contactado', 'convertido', 'descartado'])->default('novo');
            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_pedidos_orcamento');
    }
};
