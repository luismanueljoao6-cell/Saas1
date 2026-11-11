<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registo SÓ DE ACRESCENTAR dos abatimentos de adiantamentos (FA) em faturas finais (FT).
     * Em MySQL, triggers recusam UPDATE e DELETE (um abatimento errado corrige-se com um
     * processo próprio, nunca editando a linha).
     * A FK da empresa é RESTRICT (não CASCADE): empresas com registos fiscais não se eliminam
     * (encerram-se/desativam-se), o que é compatível com os triggers de imutabilidade.
     */
    public function up(): void
    {
        Schema::create('adiantamento_abatimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('fatura_adiantamento_id')->constrained('faturas');
            $table->foreignId('fatura_final_id')->constrained('faturas');
            $table->unsignedBigInteger('valor_centimos');
            $table->string('chave', 64);
            $table->timestamps();

            $table->unique(['empresa_id', 'chave']);
            $table->index(['empresa_id', 'fatura_adiantamento_id']);
            $table->index(['empresa_id', 'fatura_final_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER adiantamento_abatimentos_sem_update BEFORE UPDATE ON adiantamento_abatimentos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Os abatimentos de adiantamentos são imutáveis.'");
            DB::unprepared("CREATE TRIGGER adiantamento_abatimentos_sem_delete BEFORE DELETE ON adiantamento_abatimentos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Os abatimentos de adiantamentos são imutáveis.'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS adiantamento_abatimentos_sem_update');
            DB::unprepared('DROP TRIGGER IF EXISTS adiantamento_abatimentos_sem_delete');
        }

        Schema::dropIfExists('adiantamento_abatimentos');
    }
};
