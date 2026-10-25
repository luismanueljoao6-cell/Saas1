<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - motivo_isencao: código do motivo de isenção/não sujeição (taxExemptionCode,
     *   3 caracteres) das linhas a 0%. Fica gravado na linha — documentos emitidos
     *   continuam imutáveis (triggers das linhas) e a nota de crédito copia-o da fatura.
     * - faturas.rejeitado_documento_no: documentNo da fatura rejeitada/inválida que
     *   este documento corrige (documentStatus "C" + rejectedDocumentNo na AGT; regra E46:
     *   a correção de um documento rejeitado faz-se com um NOVO número de documento).
     */
    public function up(): void
    {
        foreach (['fatura_linhas', 'notas_credito_debito_linhas'] as $tabela) {
            if (! Schema::hasColumn($tabela, 'motivo_isencao')) {
                Schema::table($tabela, function (Blueprint $table) {
                    $table->string('motivo_isencao', 3)->nullable();
                });
            }
        }

        if (! Schema::hasColumn('faturas', 'rejeitado_documento_no')) {
            Schema::table('faturas', function (Blueprint $table) {
                $table->string('rejeitado_documento_no', 60)->nullable();
                $table->index(['empresa_id', 'rejeitado_documento_no']);
            });
        }
    }

    public function down(): void
    {
        foreach (['fatura_linhas', 'notas_credito_debito_linhas'] as $tabela) {
            if (Schema::hasColumn($tabela, 'motivo_isencao')) {
                Schema::table($tabela, function (Blueprint $table) {
                    $table->dropColumn('motivo_isencao');
                });
            }
        }

        if (Schema::hasColumn('faturas', 'rejeitado_documento_no')) {
            Schema::table('faturas', function (Blueprint $table) {
                $table->dropIndex(['empresa_id', 'rejeitado_documento_no']);
                $table->dropColumn('rejeitado_documento_no');
            });
        }
    }
};
