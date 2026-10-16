<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABELAS = ['faturas', 'notas_credito_debito', 'recibos'];

    /**
     * Dados do cliente congelados no documento no momento da emissão (NIF,
     * nome e país — os campos que entram na assinatura). Editar o cliente
     * depois já não altera o que foi assinado, impresso ou enviado à AGT.
     * Documentos anteriores ficam com estes campos vazios e continuam a usar
     * os dados atuais do cliente.
     */
    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            if (Schema::hasColumn($tabela, 'cliente_nome')) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) {
                $table->string('cliente_nif', 50)->nullable();
                $table->string('cliente_nome', 255)->nullable();
                $table->string('cliente_pais', 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela) {
            if (! Schema::hasColumn($tabela, 'cliente_nome')) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn(['cliente_nif', 'cliente_nome', 'cliente_pais']);
            });
        }
    }
};
