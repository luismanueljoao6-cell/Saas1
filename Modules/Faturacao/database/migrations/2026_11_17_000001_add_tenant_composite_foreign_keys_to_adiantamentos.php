<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A empresa indicada no abatimento tem de ser a proprietária das DUAS
     * faturas. As FKs simples por id não garantem essa relação multiempresa.
     *
     * O índice composto em faturas é necessário para a FK composta em MySQL
     * e também documenta a chave de propriedade usada pela relação.
     */
    public function up(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->unique(['empresa_id', 'id'], 'faturas_empresa_id_id_unique');
        });

        Schema::table('adiantamento_abatimentos', function (Blueprint $table) {
            $table->foreign(
                ['empresa_id', 'fatura_adiantamento_id'],
                'abatimentos_empresa_fa_fk',
            )->references(['empresa_id', 'id'])
                ->on('faturas')
                ->restrictOnDelete();

            $table->foreign(
                ['empresa_id', 'fatura_final_id'],
                'abatimentos_empresa_ft_fk',
            )->references(['empresa_id', 'id'])
                ->on('faturas')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('adiantamento_abatimentos', function (Blueprint $table) {
            $table->dropForeign('abatimentos_empresa_fa_fk');
            $table->dropForeign('abatimentos_empresa_ft_fk');
        });

        Schema::table('faturas', function (Blueprint $table) {
            $table->dropUnique('faturas_empresa_id_id_unique');
        });
    }
};
