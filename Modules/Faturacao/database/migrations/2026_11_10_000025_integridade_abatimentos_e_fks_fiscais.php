<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fecha na própria base de dados o que o serviço já valida em PHP:
     *
     * 1. Um abatimento só pode ligar duas faturas DIFERENTES da empresa indicada
     *    (uma operação direta na BD não associa documentos de empresas distintas).
     * 2. Paridade SQLite/MySQL na imutabilidade dos abatimentos: o 000024 só criava
     *    os triggers em MySQL, por isso a suite SQLite não provava a regra.
     * 3. As submissões AGT deixam de ser eliminadas em cascata com a empresa
     *    (são evidência fiscal, tal como faturas, séries, notas e recibos, que o
     *    000010 já tinha passado a RESTRICT).
     *
     * Política de encerramento: uma empresa com documentos fiscais nunca se elimina
     * definitivamente. Encerra-se (Empresa usa SoftDeletes) e os registos ficam
     * retidos; o prazo de retenção legal tem de ser confirmado antes de qualquer
     * rotina de purga. As FK RESTRICT garantem que um forceDelete falha de forma
     * limpa, sem tocar nos triggers de imutabilidade.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $this->triggersMysql();
            $this->agtSubmissoesParaRestrict();
        } elseif ($driver === 'sqlite') {
            $this->triggersSqlite();
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS adiantamento_abatimentos_mesma_empresa');

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS adiantamento_abatimentos_sem_update');
            DB::unprepared('DROP TRIGGER IF EXISTS adiantamento_abatimentos_sem_delete');
        }
        // A FK RESTRICT das submissões não se reverte de propósito (voltar a CASCADE reabriria o buraco).
    }

    protected function triggersMysql(): void
    {
        DB::unprepared("
            CREATE TRIGGER adiantamento_abatimentos_mesma_empresa BEFORE INSERT ON adiantamento_abatimentos FOR EACH ROW
            BEGIN
                IF NEW.fatura_adiantamento_id = NEW.fatura_final_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O adiantamento e a fatura final tem de ser documentos diferentes.';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM faturas WHERE id = NEW.fatura_adiantamento_id AND empresa_id = NEW.empresa_id)
                   OR NOT EXISTS (SELECT 1 FROM faturas WHERE id = NEW.fatura_final_id AND empresa_id = NEW.empresa_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O adiantamento e a fatura final tem de pertencer a empresa indicada.';
                END IF;
            END
        ");
    }

    protected function triggersSqlite(): void
    {
        DB::unprepared("
            CREATE TRIGGER adiantamento_abatimentos_mesma_empresa BEFORE INSERT ON adiantamento_abatimentos
            BEGIN
                SELECT RAISE(ABORT, 'O adiantamento e a fatura final tem de ser documentos diferentes.')
                WHERE NEW.fatura_adiantamento_id = NEW.fatura_final_id;
                SELECT RAISE(ABORT, 'O adiantamento e a fatura final tem de pertencer a empresa indicada.')
                WHERE NOT EXISTS (SELECT 1 FROM faturas WHERE id = NEW.fatura_adiantamento_id AND empresa_id = NEW.empresa_id)
                   OR NOT EXISTS (SELECT 1 FROM faturas WHERE id = NEW.fatura_final_id AND empresa_id = NEW.empresa_id);
            END
        ");
        DB::unprepared("
            CREATE TRIGGER adiantamento_abatimentos_sem_update BEFORE UPDATE ON adiantamento_abatimentos
            BEGIN
                SELECT RAISE(ABORT, 'Os abatimentos de adiantamentos são imutáveis.');
            END
        ");
        DB::unprepared("
            CREATE TRIGGER adiantamento_abatimentos_sem_delete BEFORE DELETE ON adiantamento_abatimentos
            BEGIN
                SELECT RAISE(ABORT, 'Os abatimentos de adiantamentos são imutáveis.');
            END
        ");
    }

    protected function agtSubmissoesParaRestrict(): void
    {
        if (! Schema::hasTable('agt_submissoes')) {
            return;
        }

        foreach (Schema::getForeignKeys('agt_submissoes') as $fk) {
            if ($fk['columns'] === ['empresa_id'] && strtolower((string) $fk['on_delete']) === 'cascade') {
                Schema::table('agt_submissoes', function (Blueprint $t) use ($fk) {
                    $t->dropForeign($fk['name']);
                    $t->foreign('empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                });
            }
        }
    }
};
