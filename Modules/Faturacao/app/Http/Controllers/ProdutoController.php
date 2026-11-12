<?php

namespace Modules\\Faturacao\\Http\\Controllers;

use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Routing\\Controller;
use Illuminate\\Validation\\Rule;
use Illuminate\\View\\View;
use Modules\\Faturacao\\Models\\CategoriaProduto;
use Modules\\Faturacao\\Models\\Produto;

class ProdutoController extends Controller
{
    public function index(): View
    {
        return view('faturacao::produtos.index', [
            'produtos' => Produto::with('categoria')->ativos()->orderBy('nome')->paginate(20),
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
        ]);

        Produto::create([
            ...$dados,
            'empresa_id' => $empresaId,
            'unidade' => $dados['unidade'] ?? 'un',
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
