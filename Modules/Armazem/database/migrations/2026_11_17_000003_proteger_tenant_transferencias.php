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

        Schema::table('armazens', function (Blueprint $table): void {
            $table->unique(['empresa_id', 'id'], 'armazens_empresa_id_id_unique');
        });

        $this->garantirIndiceUnico('produtos', ['empresa_id', 'id'], 'armazem_transferencias_produto_empresa_id_id_unique');

        Schema::table('armazem_transferencias', function (Blueprint $table): void {
            $table->unique(['empresa_id', 'id'], 'armazem_transferencias_empresa_id_id_unique');
            $table->foreign(['empresa_id', 'armazem_origem_id'], 'armazem_transferencias_empresa_origem_fk')
                ->references(['empresa_id', 'id'])->on('armazens')->restrictOnDelete();
            $table->foreign(['empresa_id', 'armazem_destino_id'], 'armazem_transferencias_empresa_destino_fk')
                ->references(['empresa_id', 'id'])->on('armazens')->restrictOnDelete();
        });
        Schema::table('armazem_transferencia_linhas', function (Blueprint $table): void {
            $table->unique(['empresa_id', 'id'], 'armazem_transferencia_linhas_empresa_id_id_unique');
            $table->foreign(['empresa_id', 'transferencia_id'], 'armazem_transferencia_linhas_empresa_transferencia_fk')
                ->references(['empresa_id', 'id'])->on('armazem_transferencias')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'produto_id'], 'armazem_transferencia_linhas_empresa_produto_fk')
                ->references(['empresa_id', 'id'])->on('produtos')->restrictOnDelete();
        });
        Schema::table('armazem_transferencia_rececoes', function (Blueprint $table): void {
            $table->unique(['empresa_id', 'id'], 'armazem_transferencia_rececoes_empresa_id_id_unique');
            $table->foreign(['empresa_id', 'transferencia_id'], 'armazem_transferencia_rececoes_empresa_transferencia_fk')
                ->references(['empresa_id', 'id'])->on('armazem_transferencias')->cascadeOnDelete();
        });
        Schema::table('armazem_transferencia_rececao_linhas', function (Blueprint $table): void {
            $table->foreign(['empresa_id', 'rececao_id'], 'armazem_transferencia_rececao_linhas_empresa_rececao_fk')
                ->references(['empresa_id', 'id'])->on('armazem_transferencia_rececoes')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'transferencia_linha_id'], 'armazem_transferencia_rececao_linhas_empresa_linha_fk')
                ->references(['empresa_id', 'id'])->on('armazem_transferencia_linhas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('armazem_transferencia_rececao_linhas', function (Blueprint $table): void {
            $table->dropForeign('armazem_transferencia_rececao_linhas_empresa_rececao_fk');
            $table->dropForeign('armazem_transferencia_rececao_linhas_empresa_linha_fk');
        });
        Schema::table('armazem_transferencia_rececoes', function (Blueprint $table): void {
            $table->dropForeign('armazem_transferencia_rececoes_empresa_transferencia_fk');
            $table->dropUnique('armazem_transferencia_rececoes_empresa_id_id_unique');
        });
        Schema::table('armazem_transferencia_linhas', function (Blueprint $table): void {
            $table->dropForeign('armazem_transferencia_linhas_empresa_transferencia_fk');
            $table->dropForeign('armazem_transferencia_linhas_empresa_produto_fk');
            $table->dropUnique('armazem_transferencia_linhas_empresa_id_id_unique');
        });
        Schema::table('armazem_transferencias', function (Blueprint $table): void {
            $table->dropForeign('armazem_transferencias_empresa_origem_fk');
            $table->dropForeign('armazem_transferencias_empresa_destino_fk');
            $table->dropUnique('armazem_transferencias_empresa_id_id_unique');
        });

        if ($this->indiceExiste('produtos', 'armazem_transferencias_produto_empresa_id_id_unique')) {
            Schema::table('produtos', function (Blueprint $table): void {
                $table->dropUnique('armazem_transferencias_produto_empresa_id_id_unique');
            });
        }

        Schema::table('armazens', function (Blueprint $table): void {
            $table->dropUnique('armazens_empresa_id_id_unique');
        });
    }

    protected function garantirIndiceUnico(string $tabela, array $colunas, string $nome): void
    {
        $existe = collect(Schema::getIndexes($tabela))->contains(
            fn (array $indice): bool => ($indice['unique'] ?? false) && ($indice['columns'] ?? []) === $colunas
        );

        if (! $existe) {
            Schema::table($tabela, function (Blueprint $table) use ($colunas, $nome): void {
                $table->unique($colunas, $nome);
            });
        }
    }

    protected function indiceExiste(string $tabela, string $nome): bool
    {
        return collect(Schema::getIndexes($tabela))->contains(
            fn (array $indice): bool => ($indice['name'] ?? null) === $nome
        );
    }

    protected function validarDadosExistentes(): void
    {
        $transferenciaArmazemInvalida = DB::table('armazem_transferencias as t')
            ->join('armazens as origem', 'origem.id', '=', 't.armazem_origem_id')
            ->join('armazens as destino', 'destino.id', '=', 't.armazem_destino_id')
            ->where(function ($query): void {
                $query->whereColumn('t.empresa_id', '!=', 'origem.empresa_id')
                    ->orWhereColumn('t.empresa_id', '!=', 'destino.empresa_id');
            })
            ->exists();

        $linhaTransferenciaInvalida = DB::table('armazem_transferencia_linhas as l')
            ->join('armazem_transferencias as t', 't.id', '=', 'l.transferencia_id')
            ->join('produtos as p', 'p.id', '=', 'l.produto_id')
            ->where(function ($query): void {
                $query->whereColumn('l.empresa_id', '!=', 't.empresa_id')
                    ->orWhereColumn('l.empresa_id', '!=', 'p.empresa_id');
            })
            ->exists();

        $rececaoInvalida = DB::table('armazem_transferencia_rececoes as r')
            ->join('armazem_transferencias as t', 't.id', '=', 'r.transferencia_id')
            ->whereColumn('r.empresa_id', '!=', 't.empresa_id')
            ->exists();

        $linhaRececaoInvalida = DB::table('armazem_transferencia_rececao_linhas as rl')
            ->join('armazem_transferencia_rececoes as r', 'r.id', '=', 'rl.rececao_id')
            ->join('armazem_transferencia_linhas as l', 'l.id', '=', 'rl.transferencia_linha_id')
            ->where(function ($query): void {
                $query->whereColumn('rl.empresa_id', '!=', 'r.empresa_id')
                    ->orWhereColumn('rl.empresa_id', '!=', 'l.empresa_id');
            })
            ->exists();

        if ($transferenciaArmazemInvalida || $linhaTransferenciaInvalida || $rececaoInvalida || $linhaRececaoInvalida) {
            throw new RuntimeException(
                'Migração interrompida: existem transferências, armazéns, produtos ou receções associados a empresas diferentes. Corrige os vínculos antes de aplicar as FKs compostas.'
            );
        }
    }
};
