<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;

/**
 * Gestão, pela Receção, dos leads recebidos no formulário público da
 * landing page (secção G). A conversão em Cliente/Projeto real continua a
 * fazer-se nos ecrãs já existentes (Faturacao\ClienteController,
 * ProjetoMusicalController::create) — aqui só se acompanha o estado do
 * pedido em si.
 */
class SolicitacaoOrcamentoController extends Controller
{
    public function index(): View
    {
        return view('estudiomusica::solicitacoes.index', [
            'solicitacoes' => SolicitacaoOrcamento::latest()->paginate(20),
        ]);
    }

    public function atualizarEstado(Request $request, int $solicitacao): RedirectResponse
    {
        $request->validate([
            'estado' => ['required', Rule::in(['pendente', 'contactado', 'convertido', 'descartado'])],
        ]);

        $solicitacao = SolicitacaoOrcamento::findOrFail($solicitacao);
        $solicitacao->update(['estado' => $request->input('estado')]);

        return back()->with('sucesso', 'Pedido atualizado.');
    }
}
