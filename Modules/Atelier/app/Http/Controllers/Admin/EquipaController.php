<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\AtribuirPapelRequest;
use Modules\Atelier\Services\EquipaService;

/**
 * Gere só a ATRIBUIÇÃO de papéis a utilizadores já existentes — ver o aviso
 * em EquipaService sobre a ausência, ainda, de um fluxo de convite de novos
 * utilizadores no Core.
 */
class EquipaController extends Controller
{
    public function __construct(protected EquipaService $equipaService)
    {
    }

    public function index(): View
    {
        return view('atelier::equipa.index', [
            'utilizadores' => request()->user()->empresa->utilizadores()->with('roles')->orderBy('name')->get(),
            'papelSecretaria' => config('atelier.papel_secretaria'),
            'papelCostureira' => config('atelier.papel_costureira'),
        ]);
    }

    public function atribuir(AtribuirPapelRequest $request): RedirectResponse
    {
        // User não usa BelongsToTenant (não podia: o próprio Auth::user()
        // que identifica o tenant corre ANTES de haver um tenant definido —
        // ver IdentificarTenant). Por isso a filtragem por empresa aqui tem
        // de ser explícita, via a relação, e não pode confiar num scope
        // automático.
        $utilizador = $request->user()->empresa->utilizadores()->findOrFail($request->validated('utilizador_id'));

        $this->equipaService->atribuirPapel($utilizador, $request->validated('papel'));

        return back()->with('sucesso', "Papel \"{$request->validated('papel')}\" atribuído a {$utilizador->name}.");
    }

    public function remover(AtribuirPapelRequest $request): RedirectResponse
    {
        $utilizador = $request->user()->empresa->utilizadores()->findOrFail($request->validated('utilizador_id'));

        $this->equipaService->removerPapel($utilizador, $request->validated('papel'));

        return back()->with('sucesso', "Papel removido de {$utilizador->name}.");
    }
}
