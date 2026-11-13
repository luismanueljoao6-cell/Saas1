<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Models\PedidoOrcamento;

/**
 * Leads do formulário público da landing page (requisito F.3). Mutação
 * simples de um único campo — mantida sem FormRequest dedicado de
 * propósito, para não multiplicar classes por uma validação trivial.
 *
 * IMPORTANTE: {pedidoOrcamento} chega como int, nunca como PedidoOrcamento
 * tipado (route model binding implícito). O Laravel resolve o binding
 * ANTES do middleware 'tenant' correr — nessa altura ainda não há empresa
 * definida, e a TenantScope (fail-closed) faria o binding devolver 404.
 *
 * Os leads têm dados pessoais (nome, contacto): só Administrador e
 * Secretária os veem ou alteram.
 */
class OrcamentoController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasAnyRole(['Administrador', 'Secretária']), 403);

        $dados = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $orcamentos = PedidoOrcamento::query()
            ->when($dados['q'] ?? null, fn ($query, string $termo) => $query->where(function ($filtro) use ($termo): void {
                $termo = trim($termo);
                $filtro->where('nome', 'like', "%{$termo}%")
                    ->orWhere('contacto', 'like', "%{$termo}%")
                    ->orWhere('categoria_interesse', 'like', "%{$termo}%")
                    ->orWhere('mensagem', 'like', "%{$termo}%");
            }))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('atelier::orcamentos.index', compact('orcamentos'));
    }

    public function atualizarEstado(Request $request, int $pedidoOrcamento): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['Administrador', 'Secretária']), 403);

        $pedidoOrcamento = PedidoOrcamento::findOrFail($pedidoOrcamento);

        $dados = $request->validate([
            'estado' => ['required', 'string', 'in:novo,contactado,convertido,descartado'],
        ]);

        $pedidoOrcamento->update($dados);

        return back()->with('sucesso', 'Estado atualizado.');
    }
}
