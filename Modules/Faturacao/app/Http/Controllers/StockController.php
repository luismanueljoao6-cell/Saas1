<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Models\StockMovimento;
use Modules\Faturacao\Services\StockService;

class StockController extends Controller
{
    public function index(): View
    {
        return view('faturacao::stock.index', [
            'produtos' => Produto::query()
                ->with('stockSaldo')
                ->where('controla_stock', true)
                ->orderBy('nome')
                ->get(),
            'movimentos' => StockMovimento::query()
                ->with('produto')
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        $empresa = Empresa::query()->findOrFail($request->user()->empresa_id);
        $regrasQuantidade = ['required', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'];
        if ($request->input('tipo') !== 'ajuste') {
            $regrasQuantidade[] = 'gt:0';
        }

        $dados = $request->validate([
            'produto_id' => ['required', 'integer', 'exists:produtos,id'],
            'tipo' => ['required', 'in:entrada,saida,ajuste'],
            'quantidade' => $regrasQuantidade,
            'motivo' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $produto = Produto::query()
            ->where('empresa_id', $empresa->id)
            ->where('controla_stock', true)
            ->findOrFail($dados['produto_id']);

        $quantidade = (string) $dados['quantidade'];
        $motivo = $dados['motivo'];
        $utilizadorId = (int) $request->user()->id;

        match ($dados['tipo']) {
            'entrada' => $stock->entrada($empresa, $produto, $quantidade, $motivo, $utilizadorId),
            'saida' => $stock->saida($empresa, $produto, $quantidade, $motivo, $utilizadorId),
            'ajuste' => $stock->ajustar($empresa, $produto, $quantidade, $motivo, $utilizadorId),
        };

        return to_route('faturacao.stock.index')->with('sucesso', 'Movimento de stock registado.');
    }
}
