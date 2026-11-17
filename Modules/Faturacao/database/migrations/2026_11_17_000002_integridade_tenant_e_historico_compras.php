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

        Schema::table('fornecedores', function (Blueprint $table) {
            $table->unique(['empresa_id', 'id'], 'fornecedores_empresa_id_id_unique');
        });
        $this->garantirIndiceUnico('produtos', ['empresa_id', 'id'], 'produtos_empresa_id_id_unique');
        Schema::table('compras', function (Blueprint $table) {
            $table->unique(['empresa_id', 'id'], 'compras_empresa_id_id_unique');
        });

        // Compras recebidas e os seus pagamentos são histórico financeiro:
        // eliminar uma compra/empresa não pode apagar linhas ou pagamentos em cascata.
        $this->substituirFk('compras', ['empresa_id'], 'empresas', ['id'], 'restrict');
        $this->substituirFk('compra_linhas', ['compra_id'], 'compras', ['id'], 'restrict');
        $this->substituirFk('compra_pagamentos', ['compra_id'], 'compras', ['id'], 'restrict');

        Schema::table('compras', function (Blueprint $table) {
            $table->foreign(['empresa_id', 'fornecedor_id'], 'compras_empresa_fornecedor_fk')
                ->references(['empresa_id', 'id'])->on('fornecedores')->restrictOnDelete();
        });
        Schema::table('compra_linhas', function (Blueprint $table) {
            $table->foreign(['empresa_id', 'compra_id'], 'compra_linhas_empresa_compra_fk')
                ->references(['empresa_id', 'id'])->on('compras')->restrictOnDelete();
            $table->foreign(['empresa_id', 'produto_id'], 'compra_linhas_empresa_produto_fk')
                ->references(['empresa_id', 'id'])->on('produtos')->restrictOnDelete();
        });
        Schema::table('compra_pagamentos', function (Blueprint $table) {
            $table->foreign(['empresa_id', 'compra_id'], 'compra_pagamentos_empresa_compra_fk')
                ->references(['empresa_id', 'id'])->on('compras')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compra_pagamentos', function (Blueprint $table) {
            $table->dropForeign('compra_pagamentos_empresa_compra_fk');
        });
        Schema::table('compra_linhas', function (Blueprint $table) {
            $table->dropForeign('compra_linhas_empresa_compra_fk');
            $table->dropForeign('compra_linhas_empresa_produto_fk');
        });
        Schema::table('compras', function (Blueprint $table) {
            $table->dropForeign('compras_empresa_fornecedor_fk');
        });

        $this->substituirFk('compras', ['empresa_id'], 'empresas', ['id'], 'cascade');
        $this->substituirFk('compra_linhas', ['compra_id'], 'compras', ['id'], 'cascade');
        $this->substituirFk('compra_pagamentos', ['compra_id'], 'compras', ['id'], 'cascade');

        Schema::table('compras', function (Blueprint $table) {
            $table->dropUnique('compras_empresa_id_id_unique');
        });
        if ($this->indiceExiste('produtos', 'produtos_empresa_id_id_unique')) {
            Schema::table('produtos', function (Blueprint $table) {
                $table->dropUnique('produtos_empresa_id_id_unique');
            });
        }
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->dropUnique('fornecedores_empresa_id_id_unique');
        });
    }

    protected function garantirIndiceUnico(string $tabela, array $colunas, string $nome): void
    {
        $existe = collect(Schema::getIndexes($tabela))->contains(
            fn (array $indice): bool => ($indice['unique'] ?? false) && ($indice['columns'] ?? []) === $colunas
        );

        if (! $existe) {
            Schema::table($tabela, function (Blueprint $table) use ($colunas, $nome) {
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
        $compraFornecedorInvalido = DB::table('compras as c')
            ->join('fornecedores as f', 'f.id', '=', 'c.fornecedor_id')
            ->whereColumn('c.empresa_id', '!=', 'f.empresa_id')
            ->exists();

        $linhaCompraInvalida = DB::table('compra_linhas as l')
            ->join('compras as c', 'c.id', '=', 'l.compra_id')
            ->join('produtos as p', 'p.id', '=', 'l.produto_id')
            ->where(function ($query) {
                $query->whereColumn('l.empresa_id', '!=', 'c.empresa_id')
                    ->orWhereColumn('l.empresa_id', '!=', 'p.empresa_id');
            })
            ->exists();

        $pagamentoCompraInvalido = DB::table('compra_pagamentos as p')
            ->join('compras as c', 'c.id', '=', 'p.compra_id')
            ->whereColumn('p.empresa_id', '!=', 'c.empresa_id')
            ->exists();

        if ($compraFornecedorInvalido || $linhaCompraInvalida || $pagamentoCompraInvalido) {
            throw new RuntimeException(
                'Migração interrompida: existem compras, linhas ou pagamentos associados a empresas diferentes. Corrige os registos antes de aplicar as FKs compostas.'
            );
        }
    }

    protected function substituirFk(
        string $tabela,
        array $colunas,
        string $tabelaReferenciada,
        array $colunasReferenciadas,
        string $aoApagar,
    ): void {
        foreach (Schema::getForeignKeys($tabela) as $fk) {
            if ($fk['columns'] === $colunas && $fk['foreign_table'] === $tabelaReferenciada) {
                Schema::table($tabela, function (Blueprint $table) use ($colunas) {
                    $table->dropForeign($colunas);
                });
            }
        }

        Schema::table($tabela, function (Blueprint $table) use ($colunas, $tabelaReferenciada, $colunasReferenciadas, $aoApagar) {
            $foreign = $table->foreign($colunas)
                ->references($colunasReferenciadas)
                ->on($tabelaReferenciada);

            if ($aoApagar === 'restrict') {
                $foreign->restrictOnDelete();
            } else {
                $foreign->cascadeOnDelete();
            }
        });
    }
};
