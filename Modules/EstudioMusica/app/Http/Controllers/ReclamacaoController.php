<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\EstudioMusica\Models\Reclamacao;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;

/** Reclamações recebidas pela landing: ver (equipa do estúdio) e responder (gerir-sessoes). */
class ReclamacaoController extends Controller
{
    public function index(): View
    {
        return view('estudiomusica::reclamacoes.index', [
            'reclamacoes' => Reclamacao::query()->latest()->paginate(20),
        ]);
    }

    public function atualizar(Request $request, int $reclamacao, NotificacaoEstudioService $notificacao): RedirectResponse
    {
        $dados = $request->validate([
            'estado' => ['required', Rule::in(['recebida', 'em_analise', 'resolvida'])],
            'resposta' => ['nullable', 'string', 'max:3000'],
        ]);

        $reclamacao = Reclamacao::findOrFail($reclamacao);
        $respostaNova = filled($dados['resposta'] ?? null) && $dados['resposta'] !== $reclamacao->resposta;

        $reclamacao->fill([
            'estado' => $dados['estado'],
            'resposta' => $dados['resposta'] ?? null,
            'respondida_em' => $respostaNova ? now() : $reclamacao->respondida_em,
        ])->save();

        if ($respostaNova) {
            $notificacao->reclamacaoRespondida($reclamacao);
        }

        return back()->with('sucesso', $respostaNova ? 'Resposta enviada ao cliente.' : 'Reclamação atualizada.');
    }
}
