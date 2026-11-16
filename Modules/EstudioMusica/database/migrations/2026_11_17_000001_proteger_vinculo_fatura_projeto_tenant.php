<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        $invalido = DB::table('projeto_musical_fatura as pmf')
            ->join('projetos_musicais as p', 'p.id', '=', 'pmf.projeto_musical_id')
            ->join('faturas as f', 'f.id', '=', 'pmf.fatura_id')
            ->whereColumn('p.empresa_id', '!=', 'f.empresa_id')
            ->exists();

        if ($invalido) {
            throw new RuntimeException(
                'Migração interrompida: existem projetos musicais associados a faturas de outra empresa. Corrige os vínculos antes de aplicar a proteção.'
            );
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS projeto_musical_fatura_mesma_empresa_insert
BEFORE INSERT ON projeto_musical_fatura
BEGIN
    SELECT RAISE(ABORT, 'O projeto musical e a fatura têm de pertencer à mesma empresa.')
    WHERE NOT EXISTS (
        SELECT 1
        FROM projetos_musicais p
        JOIN faturas f ON f.id = NEW.fatura_id
        WHERE p.id = NEW.projeto_musical_id
          AND p.empresa_id = f.empresa_id
    );
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS projeto_musical_fatura_mesma_empresa_update
BEFORE UPDATE ON projeto_musical_fatura
BEGIN
    SELECT RAISE(ABORT, 'O projeto musical e a fatura têm de pertencer à mesma empresa.')
    WHERE NOT EXISTS (
        SELECT 1
        FROM projetos_musicais p
        JOIN faturas f ON f.id = NEW.fatura_id
        WHERE p.id = NEW.projeto_musical_id
          AND p.empresa_id = f.empresa_id
    );
END
SQL);

            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER projeto_musical_fatura_mesma_empresa_insert
BEFORE INSERT ON projeto_musical_fatura
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM projetos_musicais p
        JOIN faturas f ON f.id = NEW.fatura_id
        WHERE p.id = NEW.projeto_musical_id
          AND p.empresa_id = f.empresa_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'O projeto musical e a fatura têm de pertencer à mesma empresa.';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER projeto_musical_fatura_mesma_empresa_update
BEFORE UPDATE ON projeto_musical_fatura
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM projetos_musicais p
        JOIN faturas f ON f.id = NEW.fatura_id
        WHERE p.id = NEW.projeto_musical_id
          AND p.empresa_id = f.empresa_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'O projeto musical e a fatura têm de pertencer à mesma empresa.';
    END IF;
END
SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS projeto_musical_fatura_mesma_empresa_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS projeto_musical_fatura_mesma_empresa_update');
    }
};
