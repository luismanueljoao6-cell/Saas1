<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lei n.º 22/11 (Proteção de Dados Pessoais) e Lei n.º 23/11 (comunicações
     * eletrónicas): mensagens promocionais só com consentimento prévio.
     * Por omissão NINGUÉM tem consentimento — a Receção regista-o na ficha
     * do artista (ou o lead marca-o no formulário da landing).
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('aceita_marketing')->default(false);
            $table->timestamp('aceita_marketing_em')->nullable();
        });

        Schema::table('solicitacoes_orcamento', function (Blueprint $table) {
            $table->boolean('aceita_marketing')->default(false);
            $table->timestamp('consentimento_dados_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['aceita_marketing', 'aceita_marketing_em']);
        });

        Schema::table('solicitacoes_orcamento', function (Blueprint $table) {
            $table->dropColumn(['aceita_marketing', 'consentimento_dados_em']);
        });
    }
};
