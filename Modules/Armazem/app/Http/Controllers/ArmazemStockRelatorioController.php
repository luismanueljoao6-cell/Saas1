<?php

namespace Modules\Armazem\Http\Controllers;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Models\ArmazemStockMinimo;
use Modules\Armazem\Models\ArmazemStockMovimento;
use Modules\Faturacao\Models\Produto;

class ArmazemStockRelatorioController extends Controller
{
    public function index(Request $request): View
    {
        $empresaId = (int) $request->user()->empresa_id;
        $filtros = $request->validate([
            'armazem_id' => ['nullable', 'integer'],
            'pesquisa' => ['nullable', 'string', 'max:100'],
            'abaixo_minimo' => ['nullable', 'boolean'],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
        ]);

        $produtos = Produto::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where('controla_stock', true)
            ->orderBy('nome')
            ->get(['id', 'nome']);

        $armazens = Armazem::query()
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();

        $saldos = DB::table('armazem_stock_saldos as saldos')
            ->join('armazens', 'armazens.id', '=', 'saldos.armazem_id')
            ->join('produtos', 'produtos.id', '=', 'saldos.produto_id')
            ->leftJoin('armazem_stock_minimos as minimos', function (JoinClause $join): void {
                $join->on('minimos.empresa_id', '=', 'saldos.empresa_id')
                    ->on('minimos.armazem_id', '=', 'saldos.armazem_id')
                    ->on('minimos.produto_id', '=', 'saldos.produto_id');
            })
            ->where('saldos.empresa_id', $empresaId)
            ->where('armazens.empresa_id', $empresaId)
            ->where('produtos.empresa_id', $empresaId)
            ->select([
                'saldos.id',
                'saldos.armazem_id',
                'saldos.produto_id',
                'saldos.quantidade',
                'armazens.nome as armazem_nome',
                'produtos.nome as produto_nome',
                'minimos.quantidade_minima',
                DB::raw('CASE WHEN minimos.id IS NOT NULL AND saldos.quantidade <= minimos.quantidade_minima THEN 1 ELSE 0 END as abaixo_minimo'),
            ])
            ->when($filtros['armazem_id'] ?? null, fn ($query, $id) => $query->where('saldos.armazem_id', $id))
            ->when($filtros['pesquisa'] ?? null, fn ($query, $termo) => $query->where('produtos.nome', 'like', '%'.$termo.'%'))
            ->when(($filtros['abaixo_minimo'] ?? false) === true || ($filtros['abaixo_minimo'] ?? null) === '1', function ($query): void {
                $query->whereNotNull('minimos.id')
                    ->whereColumn('saldos.quantidade', '<=', 'minimos.quantidade_minima');
            })
            ->orderBy('armazens.nome')
            ->orderBy('produtos.nome')
            ->paginate(25, ['*'], 'saldos')
            ->withQueryString();

        $inicio = $filtros['data_inicio'] ?? now()->subDays(29)->toDateString();
        $fim = $filtros['data_fim'] ?? now()->toDateString();

        $movimentos = ArmazemStockMovimento::query()
            ->with(['armazem:id,nome', 'produto:id,nome', 'utilizador:id,name'])
            ->where('empresa_id', $empresaId)
            ->whereDate('created_at', '>=', $inicio)
            ->whereDate('created_at', '<=', $fim)
            ->when($filtros['armazem_id'] ?? null, fn ($query, $id) => $query->where('armazem_id', $id))
            ->when($filtros['pesquisa'] ?? null, fn ($query, $termo) => $query->whereHas('produto', fn ($produto) => $produto->where('nome', 'like', '%'.$termo.'%')))
            ->latest('id')
            ->paginate(25, ['*'], 'movimentos')
            ->withQueryString();

        $totalAlertas = DB::table('armazem_stock_minimos as minimos')
            ->leftJoin('armazem_stock_saldos as saldos', function (JoinClause $join): void {
                $join->on('saldos.empresa_id', '=', 'minimos.empresa_id')
                    ->on('saldos.armazem_id', '=', 'minimos.armazem_id')
                    ->on('saldos.produto_id', '=', 'minimos.produto_id');
            })
            ->where('minimos.empresa_id', $empresaId)
            ->whereRaw('COALESCE(saldos.quantidade, 0) <= minimos.quantidade_minima')
            ->count();

        return view('armazem::relatorios.stock', compact(
            'armazens', 'produtos', 'saldos', 'movimentos', 'totalAlertas', 'inicio', 'fim', 'filtros'
        ));
    }

    public function guardarMinimo(Request $request): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;
        $dados = $request->validate([
            'armazem_id' => ['required', 'integer'],
            'produto_id' => ['required', 'integer'],
            'quantidade_minima' => ['required', 'numeric', 'min:0', 'decimal:0,3', 'max:99999999999.999'],
        ]);

        $armazem = Armazem::query()
            ->where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->findOrFail($dados['armazem_id']);

        $produto = Produto::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('ativo', true)
            ->where('controla_stock', true)
            ->findOrFail($dados['produto_id']);

        ArmazemStockMinimo::updateOrCreate(
            [
                'empresa_id' => $empresaId,
                'armazem_id' => $armazem->id,
                'produto_id' => $produto->id,
            ],
            ['quantidade_minima' => $dados['quantidade_minima']]
        );

        // Garante que artigos recém-configurados aparecem no relatório mesmo
        // antes da primeira entrada física de stock.
        DB::table('armazem_stock_saldos')->insertOrIgnore([
            'empresa_id' => $empresaId,
            'armazem_id' => $armazem->id,
            'produto_id' => $produto->id,
            'quantidade' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('sucesso', 'Stock mínimo atualizado com sucesso.');
    }
}
