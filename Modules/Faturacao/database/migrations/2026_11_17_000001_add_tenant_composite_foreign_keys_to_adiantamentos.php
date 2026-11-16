<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A empresa indicada no abatimento tem de ser a proprietária das DUAS
     * faturas. As FKs simples por id não garantem essa relação multiempresa.
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
            )->references(['empresa_id', 'id'])->on('faturas')->restrictOnDelete();

            $table->foreign(
                ['empresa_id', 'fatura_final_id'],
                'abatimentos_empresa_ft_fk',
            )->references(['empresa_id', 'id'])->on('faturas')->restrictOnDelete();
        });

        // SQLite reconstrói a tabela ao adicionar FKs e elimina triggers.
        // Recriamo-los para preservar as regras que não são expressas pelas FKs.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS adiantamento_abatimentos_mesma_empresa
BEFORE INSERT ON adiantamento_abatimentos
BEGIN
    SELECT RAISE(ABORT, 'O adiantamento e a fatura final tem de ser documentos diferentes.')
    WHERE NEW.fatura_adiantamento_id = NEW.fatura_final_id;
    SELECT RAISE(ABORT, 'O adiantamento e a fatura final tem de pertencer a empresa indicada.')
    WHERE NOT EXISTS (
        SELECT 1 FROM faturas
        WHERE id = NEW.fatura_adiantamento_id AND empresa_id = NEW.empresa_id
    ) OR NOT EXISTS (
        SELECT 1 FROM faturas
        WHERE id = NEW.fatura_final_id AND empresa_id = NEW.empresa_id
    );
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS adiantamento_abatimentos_sem_update
BEFORE UPDATE ON adiantamento_abatimentos
BEGIN
    SELECT RAISE(ABORT, 'Os abatimentos de adiantamentos são imutáveis.');
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS adiantamento_abatimentos_sem_delete
BEFORE DELETE ON adiantamento_abatimentos
BEGIN
    SELECT RAISE(ABORT, 'Os abatimentos de adiantamentos são imutáveis.');
END
SQL);
        }
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
