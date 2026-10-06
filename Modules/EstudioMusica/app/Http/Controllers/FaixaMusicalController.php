<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;

class FaixaMusicalController extends Controller
{
    public function store(Request $request, int $projeto): RedirectResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
        ]);

        $projeto = ProjetoMusical::findOrFail($projeto);

        $projeto->faixas()->create([
            'empresa_id' => $projeto->empresa_id,
            'nome' => $dados['nome'],
            'estado' => 'agendado',
            'ordem' => $projeto->faixas()->count(),
        ]);

        return back()->with('sucesso', 'Faixa adicionada.');
    }

    /**
     * "Fluxo de Status do Projeto/Música" (secção A) — a mesma lista de 8
     * estados aplica-se à FAIXA individualmente, não só ao projeto no seu
     * todo; é o gatilho do próprio exemplo da secção D ("A sua música
     * [Nome] entrou em fase de Mixagem!"). Ver ProjetoMusicalController::atualizarEstado()
     * para a mesma ideia ao nível do projeto.
     */
    public function atualizarEstado(Request $request, int $faixa, NotificacaoEstudioService $notificacao): RedirectResponse
    {
        $request->validate([
            'estado' => ['required', Rule::in(array_keys(config('estudiomusica.estados_projeto')))],
        ]);

        $faixa = FaixaMusical::findOrFail($faixa);
        $faixa->update(['estado' => $request->input('estado')]);

        $notificacao->atualizacaoEstadoFaixa($faixa);

        return back()->with('sucesso', 'Estado da faixa atualizado.');
    }
}
