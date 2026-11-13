<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Faturacao\Models\Fornecedor;

class FornecedorController extends Controller
{
    public function index(Request $request): View
    {
        $dados = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $fornecedores = Fornecedor::query()
            ->when($dados['q'] ?? null, function ($query, string $termo): void {
                $query->where(function ($subquery) use ($termo): void {
                    $subquery->where('nome', 'like', '%'.$termo.'%')
                        ->orWhere('nif', 'like', '%'.$termo.'%')
                        ->orWhere('telefone', 'like', '%'.$termo.'%');
                });
            })
            ->orderBy('nome')->paginate(20)->withQueryString();

        return view('faturacao::fornecedores.index', compact('fornecedores'));
    }

    public function guardar(Request $request): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:180'],
            'nif' => ['nullable', 'string', 'max:30', Rule::unique('fornecedores', 'nif')->where('empresa_id', $empresaId)],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:40'],
            'morada' => ['nullable', 'string', 'max:255'],
            'pais' => ['required', 'string', 'size:2'],
            'observacoes' => ['nullable', 'string', 'max:5000'],
        ]);

        Fornecedor::create([...$dados, 'empresa_id' => $empresaId, 'ativo' => true]);

        return back()->with('sucesso', 'Fornecedor registado.');
    }
}
