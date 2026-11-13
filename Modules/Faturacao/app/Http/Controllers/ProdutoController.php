<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Faturacao\Models\CategoriaProduto;
use Modules\Faturacao\Models\Produto;

class ProdutoController extends Controller
{
    public function index(Request $request): View
    {
        $empresaId = (int) $request->user()->empresa_id;
        $dados = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria_id' => ['nullable', 'integer', Rule::exists('categorias_produtos', 'id')->where('empresa_id', $empresaId)],
            'stock' => ['nullable', 'in:baixo,sem_stock'],
        ]);

        $produtos = Produto::query()
            ->with(['categoria', 'stockSaldo'])
            ->ativos()
            ->when($dados['q'] ?? null, function ($query, string $termo): void {
                $termo = trim($termo);
                $query->where(function ($subquery) use ($termo): void {
                    $subquery->where('nome', 'like', "%{$termo}%")
                        ->orWhere('codigo', 'like', "%{$termo}%")
                        ->orWhere('codigo_barras', 'like', "%{$termo}%");
                });
            })
            ->when($dados['categoria_id'] ?? null, fn ($query, int $categoriaId) => $query->where('categoria_id', $categoriaId))
            ->when(($dados['stock'] ?? null) === 'baixo', function ($query): void {
                $query->where(function ($stock): void {
                    $stock->whereHas('stockSaldo', fn ($saldo) => $saldo->whereColumn('stock_saldos.quantidade', '<=', 'produtos.stock_minimo'))
                        ->orWhereDoesntHave('stockSaldo')->where('produtos.stock_minimo', '>', 0);
                });
            })
            ->when(($dados['stock'] ?? null) === 'sem_stock', function ($query): void {
                $query->where(function ($stock): void {
                    $stock->whereHas('stockSaldo', fn ($saldo) => $saldo->where('quantidade', '<=', 0))
                        ->orWhereDoesntHave('stockSaldo');
                });
            })
            ->orderBy('nome')
            ->paginate(20)
            ->withQueryString();

        return view('faturacao::produtos.index', [
            'produtos' => $produtos,
            'categorias' => CategoriaProduto::query()->where('ativo', true)->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;

        $dados = $request->validate([
            'codigo' => ['nullable', 'string', 'max:50'],
            'codigo_barras' => [
                'nullable',
                'regex:/^(?:[0-9]{8}|[0-9]{12}|[0-9]{13}|[0-9]{14})$/',
                Rule::unique('produtos', 'codigo_barras')->where('empresa_id', $empresaId),
            ],
            'categoria_id' => [
                'nullable',
                'integer',
                Rule::exists('categorias_produtos', 'id')->where('empresa_id', $empresaId),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'unidade' => ['nullable', 'string', 'max:20'],
            'preco_unitario' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'taxa_iva' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'controla_stock' => ['sometimes', 'boolean'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0', 'max:99999999999.999'],
        ]);

        Produto::create([
            ...$dados,
            'empresa_id' => $empresaId,
            'unidade' => $dados['unidade'] ?? 'un',
            'controla_stock' => (bool) ($dados['controla_stock'] ?? false),
            'stock_minimo' => $dados['stock_minimo'] ?? '0.000',
        ]);

        return back()->with('sucesso', 'Produto adicionado.');
    }

    public function storeCategoria(Request $request): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;

        $dados = $request->validate([
            'nome' => [
                'required',
                'string',
                'max:120',
                Rule::unique('categorias_produtos', 'nome')->where('empresa_id', $empresaId),
            ],
            'descricao' => ['nullable', 'string', 'max:500'],
        ]);

        CategoriaProduto::create([
            ...$dados,
            'empresa_id' => $empresaId,
        ]);

        return back()->with('sucesso', 'Categoria criada.');
    }
}
