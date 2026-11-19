<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->validarDadosExistentes();

        Schema::table('armazem_transferencia_rececao_linhas', function (Blueprint $table): void {
            $table->unsignedBigInteger('transferencia_id')->nullable()->after('empresa_id');
        });

        DB::table('armazem_transferencia_rececao_linhas')->update([
            'transferencia_id' => DB::raw(
                '(SELECT transferencia_id FROM armazem_transferencia_rececoes '
                .'WHERE armazem_transferencia_rececoes.id = armazem_transferencia_rececao_linhas.rececao_id)'
            ),
        ]);

        if (DB::table('armazem_transferencia_rececao_linhas')->whereNull('transferencia_id')->exists()) {
            throw new RuntimeException(
                'Migração interrompida: não foi possível determinar a transferência de todas as linhas de receção.'
            );
        }

        Schema::table('armazem_transferencia_rececao_linhas', function (Blueprint $table): void {
            $table->unsignedBigInteger('transferencia_id')->nullable(false)->change();
        });

        Schema::table('armazem_transferencia_linhas', function (Blueprint $table): void {
            $table->unique(
                ['empresa_id', 'transferencia_id', 'id'],
                'arr_trans_linhas_empresa_trans_id_unique'
            );
        });

        Schema::table('armazem_transferencia_rececoes', function (Blueprint $table): void {
            $table->unique(
                ['empresa_id', 'transferencia_id', 'id'],
                'arr_trans_rececoes_empresa_trans_id_unique'
            );
        });

        Schema::table('armazem_transferencia_rececao_linhas', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                // O SQLite identifica FKs removidas pelas colunas, não pelo nome da constraint.
                $table->dropForeign(['empresa_id', 'rececao_id']);
                $table->dropForeign(['empresa_id', 'transferencia_linha_id']);
            } else {
                $table->dropForeign('armazem_transferencia_rececao_linhas_empresa_rececao_fk');
                $table->dropForeign('armazem_transferencia_rececao_linhas_empresa_linha_fk');
            }

            $table->index(
                ['empresa_id', 'transferencia_id', 'rececao_id'],
                'arr_rec_linhas_empresa_trans_rececao_idx'
            );
            $table->index(
                ['empresa_id', 'transferencia_id', 'transferencia_linha_id'],
                'arr_rec_linhas_empresa_trans_linha_idx'
            );
            $table->foreign(
                ['empresa_id', 'transferencia_id', 'rececao_id'],
                'arr_rec_linhas_empresa_trans_rececao_fk'
            )->references(['empresa_id', 'transferencia_id', 'id'])
                ->on('armazem_transferencia_rececoes')->cascadeOnDelete();
            $table->foreign(
                ['empresa_id', 'transferencia_id', 'transferencia_linha_id'],
                'arr_rec_linhas_empresa_trans_linha_fk'
            )->references(['empresa_id', 'transferencia_id', 'id'])
                ->on('armazem_transferencia_linhas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('armazem_transferencia_rececao_linhas', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['empresa_id', 'transferencia_id', 'rececao_id']);
                $table->dropForeign(['empresa_id', 'transferencia_id', 'transferencia_linha_id']);
            } else {
                $table->dropForeign('arr_rec_linhas_empresa_trans_rececao_fk');
                $table->dropForeign('arr_rec_linhas_empresa_trans_linha_fk');
            }

            $table->dropIndex('arr_rec_linhas_empresa_trans_rececao_idx');
            $table->dropIndex('arr_rec_linhas_empresa_trans_linha_idx');
            $table->foreign(
                ['empresa_id', 'rececao_id'],
                'armazem_transferencia_rececao_linhas_empresa_rececao_fk'
            )->references(['empresa_id', 'id'])
                ->on('armazem_transferencia_rececoes')->cascadeOnDelete();
            $table->foreign(
                ['empresa_id', 'transferencia_linha_id'],
                'armazem_transferencia_rececao_linhas_empresa_linha_fk'
            )->references(['empresa_id', 'id'])
                ->on('armazem_transferencia_linhas')->restrictOnDelete();
        });

        Schema::table('armazem_transferencia_rececao_linhas', function (Blueprint $table): void {
            $table->dropColumn('transferencia_id');
        });

        Schema::table('armazem_transferencia_rececoes', function (Blueprint $table): void {
            $table->dropUnique('arr_trans_rececoes_empresa_trans_id_unique');
        });

        Schema::table('armazem_transferencia_linhas', function (Blueprint $table): void {
            $table->dropUnique('arr_trans_linhas_empresa_trans_id_unique');
        });
    }

    private function validarDadosExistentes(): void
    {
        $inconsistencia = DB::table('armazem_transferencia_rececao_linhas as rl')
            ->join('armazem_transferencia_rececoes as r', 'r.id', '=', 'rl.rececao_id')
            ->join('armazem_transferencia_linhas as l', 'l.id', '=', 'rl.transferencia_linha_id')
            ->where(function ($query): void {
                $query->whereColumn('rl.empresa_id', '!=', 'r.empresa_id')
                    ->orWhereColumn('rl.empresa_id', '!=', 'l.empresa_id')
                    ->orWhereColumn('r.transferencia_id', '!=', 'l.transferencia_id');
            })
            ->exists();

        if ($inconsistencia) {
            throw new RuntimeException(
                'Migração interrompida: existem linhas de receção associadas a uma linha de outra transferência. Reconcilia os vínculos antes de aplicar a restrição.'
            );
        }
    }
};
