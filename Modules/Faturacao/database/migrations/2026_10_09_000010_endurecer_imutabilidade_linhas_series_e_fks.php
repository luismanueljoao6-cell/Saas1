<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** linhas => [tabela_pai, coluna_fk, estado_final] */
    protected array $linhas = [
        'fatura_linhas' => ['faturas', 'fatura_id', 'emitida'],
        'notas_credito_debito_linhas' => ['notas_credito_debito', 'nota_credito_debito_id', 'emitida'],
    ];

    protected array $tabelasComEmpresaFk = ['faturas', 'series', 'notas_credito_debito', 'recibos'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach ($this->linhas as $tabela => [$pai, $fk, $final]) {
            match ($driver) {
                'mysql' => $this->triggersLinhasMysql($tabela, $pai, $fk, $final),
                'sqlite' => $this->triggersLinhasSqlite($tabela, $pai, $fk, $final),
                default => null,
            };
        }

        match ($driver) {
            'mysql' => $this->triggersSeriesMysql(),
            'sqlite' => $this->triggersSeriesSqlite(),
            default => null,
        };

        if ($driver === 'mysql') {
            $this->trocarCascadeParaRestrict();
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->linhas) as $t) {
            foreach (['insercao', 'alteracao', 'exclusao'] as $op) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}_impedir_{$op}");
            }
        }
        DB::unprepared('DROP TRIGGER IF EXISTS series_impedir_retrocesso');
        DB::unprepared('DROP TRIGGER IF EXISTS series_impedir_exclusao');
        // As FKs RESTRICT não são revertidas de propósito (voltar a CASCADE reabriria o buraco).
    }

    protected function trocarCascadeParaRestrict(): void
    {
        foreach ($this->tabelasComEmpresaFk as $tabela) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }

            foreach (Schema::getForeignKeys($tabela) as $fk) {
                if ($fk['columns'] === ['empresa_id'] && strtolower((string) $fk['on_delete']) === 'cascade') {
                    Schema::table($tabela, function (Blueprint $t) use ($fk) {
                        $t->dropForeign($fk['name']);
                        $t->foreign('empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                    });
                }
            }
        }
    }

    protected function triggersLinhasMysql(string $t, string $pai, string $fk, string $final): void
    {
        $msg = 'Documento fiscal ja emitido - as linhas nao podem ser alteradas';

        DB::unprepared("
            CREATE TRIGGER {$t}_impedir_insercao BEFORE INSERT ON {$t} FOR EACH ROW
            BEGIN
                IF (SELECT estado FROM {$pai} WHERE id = NEW.{$fk}) = '{$final}' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}';
                END IF;
            END
        ");
        DB::unprepared("
            CREATE TRIGGER {$t}_impedir_alteracao BEFORE UPDATE ON {$t} FOR EACH ROW
            BEGIN
                IF (SELECT estado FROM {$pai} WHERE id = OLD.{$fk}) = '{$final}'
                   OR (SELECT estado FROM {$pai} WHERE id = NEW.{$fk}) = '{$final}' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}';
                END IF;
            END
        ");
        DB::unprepared("
            CREATE TRIGGER {$t}_impedir_exclusao BEFORE DELETE ON {$t} FOR EACH ROW
            BEGIN
                IF (SELECT estado FROM {$pai} WHERE id = OLD.{$fk}) = '{$final}' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}';
                END IF;
            END
        ");
    }

    protected function triggersLinhasSqlite(string $t, string $pai, string $fk, string $final): void
    {
        $msg = 'Documento fiscal ja emitido - as linhas nao podem ser alteradas';

        DB::unprepared("
            CREATE TRIGGER {$t}_impedir_insercao BEFORE INSERT ON {$t}
            BEGIN
                SELECT RAISE(ABORT, '{$msg}') WHERE (SELECT estado FROM {$pai} WHERE id = NEW.{$fk}) = '{$final}';
            END
        ");
        DB::unprepared("
            CREATE TRIGGER {$t}_impedir_alteracao BEFORE UPDATE ON {$t}
            BEGIN
                SELECT RAISE(ABORT, '{$msg}')
                WHERE (SELECT estado FROM {$pai} WHERE id = OLD.{$fk}) = '{$final}'
                   OR (SELECT estado FROM {$pai} WHERE id = NEW.{$fk}) = '{$final}';
            END
        ");
        DB::unprepared("
            CREATE TRIGGER {$t}_impedir_exclusao BEFORE DELETE ON {$t}
            BEGIN
                SELECT RAISE(ABORT, '{$msg}') WHERE (SELECT estado FROM {$pai} WHERE id = OLD.{$fk}) = '{$final}';
            END
        ");
    }

    protected function triggersSeriesMysql(): void
    {
        DB::unprepared("
            CREATE TRIGGER series_impedir_retrocesso BEFORE UPDATE ON series FOR EACH ROW
            BEGIN
                IF NEW.ultimo_numero < OLD.ultimo_numero THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A numeracao de uma serie nao pode retroceder';
                END IF;
            END
        ");
        DB::unprepared("
            CREATE TRIGGER series_impedir_exclusao BEFORE DELETE ON series FOR EACH ROW
            BEGIN
                IF OLD.ultimo_numero > 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Uma serie ja utilizada nao pode ser apagada';
                END IF;
            END
        ");
    }

    protected function triggersSeriesSqlite(): void
    {
        DB::unprepared("
            CREATE TRIGGER series_impedir_retrocesso BEFORE UPDATE ON series
            BEGIN
                SELECT RAISE(ABORT, 'A numeracao de uma serie nao pode retroceder') WHERE NEW.ultimo_numero < OLD.ultimo_numero;
            END
        ");
        DB::unprepared("
            CREATE TRIGGER series_impedir_exclusao BEFORE DELETE ON series
            BEGIN
                SELECT RAISE(ABORT, 'Uma serie ja utilizada nao pode ser apagada') WHERE OLD.ultimo_numero > 0;
            END
        ");
    }
};
