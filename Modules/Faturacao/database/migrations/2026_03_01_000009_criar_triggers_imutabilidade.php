<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A trait Imutavel (nível de aplicação) só intercepta update()/delete()
     * numa instância já carregada — dispara nos eventos 'updating'/'deleting'
     * do Eloquent. Uma operação em massa como
     * Fatura::where('empresa_id', 5)->update([...]) NUNCA passa por esses
     * eventos, e por isso nunca passava pela trait. Estes triggers fecham
     * esse buraco ao nível da própria base de dados — bloqueiam SEMPRE,
     * independentemente do caminho de código (ou ferramenta externa) que
     * tente a alteração.
     *
     * Escreve sintaxe diferente consoante o driver porque SQLite (usado
     * nos testes automatizados) e MySQL (usado em produção) não partilham
     * a mesma linguagem de triggers.
     */
    protected array $tabelas = [
        'faturas' => 'emitida',
        'notas_credito_debito' => 'emitida',
        'recibos' => 'emitido',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach ($this->tabelas as $tabela => $estadoFinal) {
            if ($driver === 'mysql') {
                $this->criarTriggersMysql($tabela, $estadoFinal);
            } elseif ($driver === 'sqlite') {
                $this->criarTriggersSqlite($tabela, $estadoFinal);
            }
            // Outros drivers: sem triggers — a trait Imutavel continua a
            // proteger o caminho normal (instância única), só esta segunda
            // camada fica por implementar nesse driver.
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (array_keys($this->tabelas) as $tabela) {
            if ($driver === 'mysql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$tabela}_impedir_alteracao");
                DB::unprepared("DROP TRIGGER IF EXISTS {$tabela}_impedir_exclusao");
            } elseif ($driver === 'sqlite') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$tabela}_impedir_alteracao");
                DB::unprepared("DROP TRIGGER IF EXISTS {$tabela}_impedir_exclusao");
            }
        }
    }

    protected function criarTriggersMysql(string $tabela, string $estadoFinal): void
    {
        DB::unprepared("
            CREATE TRIGGER {$tabela}_impedir_alteracao
            BEFORE UPDATE ON {$tabela}
            FOR EACH ROW
            BEGIN
                IF OLD.estado = '{$estadoFinal}' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento fiscal ja emitido - nao pode ser alterado (corrige com Nota de Credito/Debito)';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER {$tabela}_impedir_exclusao
            BEFORE DELETE ON {$tabela}
            FOR EACH ROW
            BEGIN
                IF OLD.estado = '{$estadoFinal}' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento fiscal ja emitido - nao pode ser apagado';
                END IF;
            END
        ");
    }

    protected function criarTriggersSqlite(string $tabela, string $estadoFinal): void
    {
        DB::unprepared("
            CREATE TRIGGER {$tabela}_impedir_alteracao
            BEFORE UPDATE ON {$tabela}
            WHEN OLD.estado = '{$estadoFinal}'
            BEGIN
                SELECT RAISE(ABORT, 'Documento fiscal ja emitido - nao pode ser alterado');
            END
        ");

        DB::unprepared("
            CREATE TRIGGER {$tabela}_impedir_exclusao
            BEFORE DELETE ON {$tabela}
            WHEN OLD.estado = '{$estadoFinal}'
            BEGIN
                SELECT RAISE(ABORT, 'Documento fiscal ja emitido - nao pode ser apagado');
            END
        ");
    }
};
