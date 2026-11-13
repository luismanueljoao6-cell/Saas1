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
        $dados = $request->validate(['q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:ativos,inativos,todos']]);
        $fornecedores = Fornecedor::query()
            ->when($dados['q'] ?? null, function ($query, string $termo): void {
                $query->where(function ($subquery) use ($termo): void {
                    $subquery->where('nome', 'like', '%'.$termo.'%')
                        ->orWhere('nif', 'like', '%'.$termo.'%')
                        ->orWhere('telefone', 'like', '%'.$termo.'%');
                });
            })
            ->when(($dados['estado'] ?? 'ativos') !== 'todos', fn ($query) => $query->where('ativo', ($dados['estado'] ?? 'ativos') === 'ativos'))
            ->orderBy('nome')->paginate(20)->withQueryString();

        return view('faturacao::fornecedores.index', compact('fornecedores'));
    }

    public function atualizar(Request $request, int $fornecedorId): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;
        // Resolver depois de IdentificarTenant; evitar route model binding prematuro.
        $fornecedor = Fornecedor::query()->findOrFail($fornecedorId);
        abort_unless((int) $fornecedor->empresa_id === $empresaId, 404);

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:180'],
            'nif' => ['nullable', 'string', 'max:30', Rule::unique('fornecedores', 'nif')->where('empresa_id', $empresaId)->ignore($fornecedor->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:40'],
            'morada' => ['nullable', 'string', 'max:255'],
            'pais' => ['required', 'string', 'size:2'],
            'observacoes' => ['nullable', 'string', 'max:5000'],
        ]);

        $fornecedor->update($dados);

        return back()->with('sucesso', 'Dados do fornecedor atualizados.');
    }

    public function alternarEstado(Request $request, int $fornecedorId): RedirectResponse
    {
        $fornecedor = Fornecedor::query()->findOrFail($fornecedorId);
        abort_unless((int) $fornecedor->empresa_id === (int) $request->user()->empresa_id, 404);

        $fornecedor->update(['ativo' => ! $fornecedor->ativo]);

        return back()->with('sucesso', $fornecedor->ativo ? 'Fornecedor reativado.' : 'Fornecedor inativado.');
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
