<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conformidade (Lei n.º 22/11 — Protecção de Dados Pessoais) e retenção:
 *
 * - Marketing passa a exigir consentimento EXPLÍCITO e provado: o default de
 *   aceita_marketing passa a false e guardam-se data, origem e revogação.
 *   As linhas antigas (que herdaram o default true sem prova de consentimento)
 *   ficam com consentimento_em nulo e, por isso, deixam de receber marketing
 *   até o consentimento ser registado (ver CrmService).
 * - O formulário público de orçamento passa a guardar quando o visitante
 *   aceitou o tratamento dos dados.
 * - Apagar uma empresa deixa de apagar em cascata os pedidos (ligados a
 *   documentos fiscais, que têm de ser conservados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atelier_clientes_crm', function (Blueprint $table) {
            $table->boolean('aceita_marketing')->default(false)->change();
            $table->timestamp('consentimento_em')->nullable();
            $table->string('consentimento_origem')->nullable();
            $table->timestamp('consentimento_revogado_em')->nullable();
        });

        Schema::table('atelier_pedidos_orcamento', function (Blueprint $table) {
            $table->timestamp('consentimento_em')->nullable();
        });

        Schema::table('atelier_pedidos', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
            $table->foreign('empresa_id')->references('id')->on('empresas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('atelier_pedidos', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
            $table->foreign('empresa_id')->references('id')->on('empresas')->cascadeOnDelete();
        });

        Schema::table('atelier_pedidos_orcamento', function (Blueprint $table) {
            $table->dropColumn('consentimento_em');
        });

        Schema::table('atelier_clientes_crm', function (Blueprint $table) {
            $table->dropColumn(['consentimento_em', 'consentimento_origem', 'consentimento_revogado_em']);
            $table->boolean('aceita_marketing')->default(true)->change();
        });
    }
};
