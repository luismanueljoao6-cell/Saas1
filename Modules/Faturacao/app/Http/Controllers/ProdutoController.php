<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Faturacao\Models\Produto;

class ProdutoController extends Controller
{
    public function index(): View
    {
        return view('faturacao::produtos.index', [
            'produtos' => Produto::ativos()->orderBy('nome')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'codigo' => ['nullable', 'string', 'max:50'],
            'nome' => ['required', 'string', 'max:255'],
            'unidade' => ['nullable', 'string', 'max:20'],
            'preco_unitario' => ['required', 'numeric', 'min:0'],
            'taxa_iva' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        Produto::create([
            ...$dados,
            'empresa_id' => $request->user()->empresa_id,
            'unidade' => $dados['unidade'] ?? 'un',
        ]);

        return back()->with('sucesso', 'Produto adicionado.');
    }
}
