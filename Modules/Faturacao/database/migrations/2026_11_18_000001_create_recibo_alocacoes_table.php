<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alocações de recibos (AR/RG) por linha da fatura de origem.
     *
     * Cada linha desta tabela diz EXACTAMENTE que parte (líquido, imposto, bruto, em cêntimos)
     * de que linha de que fatura um recibo regulariza. É a fotografia fiscal imutável de onde o
     * `paymentReceipt` AGT há de ser reconstruído (docs/auditorias/fase-1/decisao-alocacoes-paymentReceipt.md).
     *
     * Aditiva: não altera nem reinterpreta recibos existentes (recibos.fatura_id continua a ser a
     * referência legada). A empresa, o recibo, a fatura e a linha têm de coincidir na própria BD
     * (FKs compostas) e a tabela é SÓ DE ACRESCENTAR (triggers recusam UPDATE e DELETE).
     *
     * Não decide o rateio de um pagamento parcial entre líquido e IVA: quem regista fornece os
     * montantes; esta estrutura só garante que são coerentes, que não sobre-alocam e que ficam.
     */
    public function up(): void
    {
        Schema::table('recibos', function (Blueprint $table) {
            if (! Schema::hasIndex('recibos', 'recibos_empresa_id_id_unique')) {
                $table->unique(['empresa_id', 'id'], 'recibos_empresa_id_id_unique');
            }
        });

        Schema::table('fatura_linhas', function (Blueprint $table) {
            if (! Schema::hasIndex('fatura_linhas', 'fatura_linhas_fatura_id_id_unique')) {
                $table->unique(['fatura_id', 'id'], 'fatura_linhas_fatura_id_id_unique');
            }
        });

        Schema::create('recibo_alocacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedBigInteger('recibo_id');
            $table->unsignedBigInteger('fatura_id');
            $table->unsignedBigInteger('fatura_linha_id');

            // Posição (base 1) da linha na fatura de origem, pela mesma ordem do AgtDocumentoBuilder
            // (lineNumber = índice + 1). NÃO é fatura_linhas.ordem, que é base 0.
            $table->unsignedInteger('numero_linha_fiscal');
            // Posição (base 1) da alocação dentro do recibo. Guardada à parte porque a leitura do
            // `lineNo` da AGT (posição na fatura de origem ou sequência no recibo) está por confirmar.
            $table->unsignedInteger('sequencia');

            $table->unsignedBigInteger('liquido_centimos');
            $table->unsignedBigInteger('imposto_centimos');
            $table->unsignedBigInteger('bruto_centimos');

            $table->string('chave', 64);
            $table->timestamps();

            $table->unique(['recibo_id', 'fatura_linha_id'], 'recibo_alocacoes_recibo_linha_unique');
            $table->unique(['recibo_id', 'sequencia'], 'recibo_alocacoes_recibo_sequencia_unique');
            $table->unique(['empresa_id', 'chave', 'fatura_linha_id'], 'recibo_alocacoes_chave_linha_unique');
            $table->index(['empresa_id', 'recibo_id'], 'recibo_alocacoes_empresa_recibo_idx');
            $table->index(['empresa_id', 'fatura_id'], 'recibo_alocacoes_empresa_fatura_idx');
            $table->index('fatura_linha_id', 'recibo_alocacoes_linha_idx');

            $table->foreign(['empresa_id', 'recibo_id'], 'recibo_alocacoes_empresa_recibo_fk')
                ->references(['empresa_id', 'id'])->on('recibos')->restrictOnDelete();
            $table->foreign(['empresa_id', 'fatura_id'], 'recibo_alocacoes_empresa_fatura_fk')
                ->references(['empresa_id', 'id'])->on('faturas')->restrictOnDelete();
            $table->foreign(['fatura_id', 'fatura_linha_id'], 'recibo_alocacoes_fatura_linha_fk')
                ->references(['fatura_id', 'id'])->on('fatura_linhas')->restrictOnDelete();
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $this->triggersMysql();
        } elseif ($driver === 'sqlite') {
            $this->triggersSqlite();
        }
    }

    /**
     * Recusa o rollback quando já existem alocações: são evidência fiscal e o DROP TABLE não passa
     * pelos triggers de imutabilidade. Um rollback com dados exige exportação e decisão humana.
     */
    public function down(): void
    {
        if (Schema::hasTable('recibo_alocacoes') && DB::table('recibo_alocacoes')->exists()) {
            throw new RuntimeException(
                'Rollback recusado: recibo_alocacoes contém alocações fiscais imutáveis. '
                .'Exporta-as e remove-as por um procedimento controlado antes de reverter.'
            );
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS recibo_alocacoes_validar_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS recibo_alocacoes_sem_update');
            DB::unprepared('DROP TRIGGER IF EXISTS recibo_alocacoes_sem_delete');
        }

        Schema::dropIfExists('recibo_alocacoes');

        // Ao criar o índice composto, o MySQL pode largar o índice automático da FK (fatura_id /
        // empresa_id) e passa a usar o composto. Sem repor o índice próprio da FK, o MySQL recusa
        // remover o composto (erro 1553). Provado em MySQL 8.0 com rollback sobre a tabela vazia.
        $this->garantirIndiceDaFk('fatura_linhas', 'fatura_id', 'fatura_linhas_fatura_id_index', 'fatura_linhas_fatura_id_id_unique');
        Schema::table('fatura_linhas', function (Blueprint $table) {
            if (Schema::hasIndex('fatura_linhas', 'fatura_linhas_fatura_id_id_unique')) {
                $table->dropUnique('fatura_linhas_fatura_id_id_unique');
            }
        });

        $this->garantirIndiceDaFk('recibos', 'empresa_id', 'recibos_empresa_id_index', 'recibos_empresa_id_id_unique');
        Schema::table('recibos', function (Blueprint $table) {
            if (Schema::hasIndex('recibos', 'recibos_empresa_id_id_unique')) {
                $table->dropUnique('recibos_empresa_id_id_unique');
            }
        });
    }

    /** Garante que existe um índice (que não seja o composto a remover) a começar em $coluna. */
    protected function garantirIndiceDaFk(string $tabela, string $coluna, string $nome, string $composto): void
    {
        $existe = collect(Schema::getIndexes($tabela))->contains(
            fn (array $indice) => $indice['name'] !== $composto && ($indice['columns'][0] ?? null) === $coluna,
        );

        if (! $existe) {
            Schema::table($tabela, fn (Blueprint $table) => $table->index($coluna, $nome));
        }
    }

    protected function triggersMysql(): void
    {
        DB::unprepared("
            CREATE TRIGGER recibo_alocacoes_validar_insert BEFORE INSERT ON recibo_alocacoes FOR EACH ROW
            BEGIN
                IF NEW.numero_linha_fiscal < 1 OR NEW.sequencia < 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A numeração fiscal da alocação começa em 1.';
                END IF;
                IF NEW.bruto_centimos <= 0 OR NEW.bruto_centimos <> NEW.liquido_centimos + NEW.imposto_centimos THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O bruto da alocação tem de ser positivo e igual ao líquido mais o imposto.';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM recibos WHERE id = NEW.recibo_id AND empresa_id = NEW.empresa_id AND estado = 'rascunho') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'As alocações só se registam enquanto o recibo é rascunho.';
                END IF;
            END
        ");
        DB::unprepared("CREATE TRIGGER recibo_alocacoes_sem_update BEFORE UPDATE ON recibo_alocacoes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'As alocações de recibos são imutáveis.'");
        DB::unprepared("CREATE TRIGGER recibo_alocacoes_sem_delete BEFORE DELETE ON recibo_alocacoes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'As alocações de recibos são imutáveis.'");
    }

    protected function triggersSqlite(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER recibo_alocacoes_validar_insert BEFORE INSERT ON recibo_alocacoes
BEGIN
    SELECT RAISE(ABORT, 'A numeração fiscal da alocação começa em 1.')
    WHERE NEW.numero_linha_fiscal < 1 OR NEW.sequencia < 1;
    SELECT RAISE(ABORT, 'O bruto da alocação tem de ser positivo e igual ao líquido mais o imposto.')
    WHERE NEW.bruto_centimos <= 0 OR NEW.bruto_centimos <> NEW.liquido_centimos + NEW.imposto_centimos;
    SELECT RAISE(ABORT, 'As alocações só se registam enquanto o recibo é rascunho.')
    WHERE NOT EXISTS (
        SELECT 1 FROM recibos
        WHERE id = NEW.recibo_id AND empresa_id = NEW.empresa_id AND estado = 'rascunho'
    );
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER recibo_alocacoes_sem_update BEFORE UPDATE ON recibo_alocacoes
BEGIN
    SELECT RAISE(ABORT, 'As alocações de recibos são imutáveis.');
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER recibo_alocacoes_sem_delete BEFORE DELETE ON recibo_alocacoes
BEGIN
    SELECT RAISE(ABORT, 'As alocações de recibos são imutáveis.');
END
SQL);
    }
};
