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

        Schema::table('armazem_inventarios', function (Blueprint $table): void {
            $table->unique(['empresa_id', 'id'], 'armazem_inventarios_empresa_id_id_unique');
            $table->foreign(['empresa_id', 'armazem_id'], 'armazem_inventarios_empresa_armazem_fk')
                ->references(['empresa_id', 'id'])->on('armazens')->restrictOnDelete();
        });

        Schema::table('armazem_inventario_linhas', function (Blueprint $table): void {
            $table->unique(
                ['empresa_id', 'inventario_id', 'id'],
                'armazem_inventario_linhas_empresa_inv_id_unique'
            );
            $table->foreign(['empresa_id', 'inventario_id'], 'armazem_inventario_linhas_empresa_inventario_fk')
                ->references(['empresa_id', 'id'])->on('armazem_inventarios')->cascadeOnDelete();
            $table->foreign(['empresa_id', 'produto_id'], 'armazem_inventario_linhas_empresa_produto_fk')
                ->references(['empresa_id', 'id'])->on('produtos')->restrictOnDelete();
        });

        Schema::table('armazem_stock_saldos', function (Blueprint $table): void {
            $table->foreign(['empresa_id', 'armazem_id'], 'armazem_stock_saldos_empresa_armazem_fk')
                ->references(['empresa_id', 'id'])->on('armazens')->restrictOnDelete();
            $table->foreign(['empresa_id', 'produto_id'], 'armazem_stock_saldos_empresa_produto_fk')
                ->references(['empresa_id', 'id'])->on('produtos')->restrictOnDelete();
        });

        Schema::table('armazem_stock_movimentos', function (Blueprint $table): void {
            $table->foreign(['empresa_id', 'armazem_id'], 'armazem_stock_movimentos_empresa_armazem_fk')
                ->references(['empresa_id', 'id'])->on('armazens')->restrictOnDelete();
            $table->foreign(['empresa_id', 'produto_id'], 'armazem_stock_movimentos_empresa_produto_fk')
                ->references(['empresa_id', 'id'])->on('produtos')->restrictOnDelete();
        });

        Schema::table('armazem_stock_minimos', function (Blueprint $table): void {
            $table->foreign(['empresa_id', 'armazem_id'], 'armazem_stock_minimos_empresa_armazem_fk')
                ->references(['empresa_id', 'id'])->on('armazens')->restrictOnDelete();
            $table->foreign(['empresa_id', 'produto_id'], 'armazem_stock_minimos_empresa_produto_fk')
                ->references(['empresa_id', 'id'])->on('produtos')->restrictOnDelete();
        });

        Schema::table('armazem_inventario_contagens', function (Blueprint $table): void {
            // A linha tem de pertencer ao mesmo inventário, não apenas à mesma empresa.
            $table->foreign(
                ['empresa_id', 'inventario_id', 'linha_id'],
                'armazem_inventario_contagens_empresa_inv_linha_fk'
            )->references(['empresa_id', 'inventario_id', 'id'])
                ->on('armazem_inventario_linhas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('armazem_inventario_contagens', function (Blueprint $table): void {
            $table->dropForeign('armazem_inventario_contagens_empresa_inv_linha_fk');
        });

        Schema::table('armazem_stock_minimos', function (Blueprint $table): void {
            $table->dropForeign('armazem_stock_minimos_empresa_armazem_fk');
            $table->dropForeign('armazem_stock_minimos_empresa_produto_fk');
        });

        Schema::table('armazem_stock_movimentos', function (Blueprint $table): void {
            $table->dropForeign('armazem_stock_movimentos_empresa_armazem_fk');
            $table->dropForeign('armazem_stock_movimentos_empresa_produto_fk');
        });

        Schema::table('armazem_stock_saldos', function (Blueprint $table): void {
            $table->dropForeign('armazem_stock_saldos_empresa_armazem_fk');
            $table->dropForeign('armazem_stock_saldos_empresa_produto_fk');
        });

        Schema::table('armazem_inventario_linhas', function (Blueprint $table): void {
            $table->dropForeign('armazem_inventario_linhas_empresa_inventario_fk');
            $table->dropForeign('armazem_inventario_linhas_empresa_produto_fk');
            $table->dropUnique('armazem_inventario_linhas_empresa_inv_id_unique');
        });

        Schema::table('armazem_inventarios', function (Blueprint $table): void {
            $table->dropForeign('armazem_inventarios_empresa_armazem_fk');
            $table->dropUnique('armazem_inventarios_empresa_id_id_unique');
        });
    }

    private function validarDadosExistentes(): void
    {
        $consultas = [
            DB::table('armazem_stock_saldos as s')
                ->join('armazens as a', 'a.id', '=', 's.armazem_id')
                ->join('produtos as p', 'p.id', '=', 's.produto_id')
                ->where(function ($query): void {
                    $query->whereColumn('s.empresa_id', '!=', 'a.empresa_id')
                        ->orWhereColumn('s.empresa_id', '!=', 'p.empresa_id');
                }),
            DB::table('armazem_stock_movimentos as m')
                ->join('armazens as a', 'a.id', '=', 'm.armazem_id')
                ->join('produtos as p', 'p.id', '=', 'm.produto_id')
                ->where(function ($query): void {
                    $query->whereColumn('m.empresa_id', '!=', 'a.empresa_id')
                        ->orWhereColumn('m.empresa_id', '!=', 'p.empresa_id');
                }),
            DB::table('armazem_stock_minimos as s')
                ->join('armazens as a', 'a.id', '=', 's.armazem_id')
                ->join('produtos as p', 'p.id', '=', 's.produto_id')
                ->where(function ($query): void {
                    $query->whereColumn('s.empresa_id', '!=', 'a.empresa_id')
                        ->orWhereColumn('s.empresa_id', '!=', 'p.empresa_id');
                }),
            DB::table('armazem_inventarios as i')
                ->join('armazens as a', 'a.id', '=', 'i.armazem_id')
                ->whereColumn('i.empresa_id', '!=', 'a.empresa_id'),
            DB::table('armazem_inventario_linhas as l')
                ->join('armazem_inventarios as i', 'i.id', '=', 'l.inventario_id')
                ->join('produtos as p', 'p.id', '=', 'l.produto_id')
                ->where(function ($query): void {
                    $query->whereColumn('l.empresa_id', '!=', 'i.empresa_id')
                        ->orWhereColumn('l.empresa_id', '!=', 'p.empresa_id');
                }),
            DB::table('armazem_inventario_contagens as c')
                ->join('armazem_inventarios as i', 'i.id', '=', 'c.inventario_id')
                ->join('armazem_inventario_linhas as l', 'l.id', '=', 'c.linha_id')
                ->where(function ($query): void {
                    $query->whereColumn('c.empresa_id', '!=', 'i.empresa_id')
                        ->orWhereColumn('c.empresa_id', '!=', 'l.empresa_id')
                        ->orWhereColumn('c.inventario_id', '!=', 'l.inventario_id');
                }),
        ];

        foreach ($consultas as $consulta) {
            if ($consulta->exists()) {
                throw new RuntimeException(
                    'Migração interrompida: existem saldos, movimentos, mínimos ou contagens associados a armazéns, produtos ou inventários de outra empresa. Corrige os vínculos antes de aplicar as FKs compostas.'
                );
            }
        }
    }
};
