<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Models\StockMovimento;
use Modules\Faturacao\Services\StockService;

class StockController extends Controller
{
    public function index(Request $request): View
    {
        $dados = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $movimentos = StockMovimento::query()
            ->with('produto')
            ->when($dados['q'] ?? null, function ($query, string $termo): void {
                $termo = trim($termo);
                $query->where(function ($subquery) use ($termo): void {
                    $subquery->where('motivo', 'like', "%{$termo}%")
                        ->orWhereHas('produto', fn ($produto) => $produto->where('nome', 'like', "%{$termo}%"));
                });
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('faturacao::stock.index', [
            'chaveIdempotencia' => (string) Str::uuid(),
            'produtos' => Produto::query()
                ->with('stockSaldo')
                ->where('controla_stock', true)
                ->orderBy('nome')
                ->get(),
            'movimentos' => $movimentos,
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
            'chave_idempotencia' => ['required', 'uuid', 'max:100'],
        ]);

        $produto = Produto::query()
            ->where('empresa_id', $empresa->id)
            ->where('controla_stock', true)
            ->findOrFail($dados['produto_id']);

        $quantidade = (string) $dados['quantidade'];
        $motivo = $dados['motivo'];
        $utilizadorId = (int) $request->user()->id;

        match ($dados['tipo']) {
            'entrada' => $stock->entrada($empresa, $produto, $quantidade, $motivo, $utilizadorId, $dados['chave_idempotencia']),
            'saida' => $stock->saida($empresa, $produto, $quantidade, $motivo, $utilizadorId, $dados['chave_idempotencia']),
            'ajuste' => $stock->ajustar($empresa, $produto, $quantidade, $motivo, $utilizadorId, $dados['chave_idempotencia']),
        };

        return to_route('faturacao.stock.index')->with('sucesso', 'Movimento de stock registado.');
    }
}
