<?php

namespace Modules\Armazem\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Services\ArmazemService;

class ArmazemController extends Controller
{
    public function index(Request $request): View
    {
        $armazens = Armazem::query()
            ->orderByDesc('principal')
            ->orderBy('nome')
            ->paginate(20)
            ->withQueryString();

        return view('armazem::index', compact('armazens'));
    }

    public function store(Request $request, ArmazemService $armazens): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:40', Rule::unique('armazens', 'codigo')->where('empresa_id', $empresaId)],
            'nome' => ['required', 'string', 'max:150'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'morada' => ['nullable', 'string', 'max:255'],
            'principal' => ['sometimes', 'boolean'],
        ]);

        $armazens->criar(
            $request->user()->empresa,
            $dados['codigo'],
            $dados['nome'],
            $dados['descricao'] ?? null,
            $dados['morada'] ?? null,
            (bool) ($dados['principal'] ?? false),
        );

        return back()->with('sucesso', 'Armazém criado com sucesso.');
    }

    public function update(Request $request, int $armazemId): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;
        $armazem = Armazem::query()->findOrFail($armazemId);

        abort_unless((int) $armazem->empresa_id === $empresaId, 404);

        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:40', Rule::unique('armazens', 'codigo')->where('empresa_id', $empresaId)->ignore($armazem->id)],
            'nome' => ['required', 'string', 'max:150'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'morada' => ['nullable', 'string', 'max:255'],
        ]);

        $armazem->update($dados);

        return back()->with('sucesso', 'Armazém atualizado.');
    }

    public function alternarEstado(Request $request, int $armazemId, ArmazemService $armazens): RedirectResponse
    {
        $armazem = Armazem::query()->findOrFail($armazemId);
        abort_unless((int) $armazem->empresa_id === (int) $request->user()->empresa_id, 404);

        try {
            $armazem = $armazens->alternarEstado($request->user()->empresa, $armazem);
        } catch (DomainException $exception) {
            return back()->with('erro', $exception->getMessage());
        }

        return back()->with('sucesso', $armazem->ativo ? 'Armazém reativado.' : 'Armazém inativado.');
    }

    public function definirPrincipal(Request $request, int $armazemId, ArmazemService $armazens): RedirectResponse
    {
        $alvo = Armazem::query()->findOrFail($armazemId);
        abort_unless((int) $alvo->empresa_id === (int) $request->user()->empresa_id, 404);

        try {
            $armazens->definirPrincipal($request->user()->empresa, $alvo);
        } catch (DomainException $exception) {
            return back()->with('erro', $exception->getMessage());
        }

        return back()->with('sucesso', 'Armazém principal atualizado.');
    }
}
