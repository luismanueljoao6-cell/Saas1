<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Core\Models\User;
use Modules\EstudioMusica\Http\Requests\AtribuirPapelEstudioRequest;
use Modules\EstudioMusica\Support\PapeisEstudio;

class EquipaEstudioController extends Controller
{
    public function index(Request $request): View
    {
        $empresa = $request->user()->empresa;

        return view('estudiomusica::equipa.index', [
            'utilizadores' => $empresa->utilizadores()->where('ativo', true)->with('roles')->orderBy('name')->get(),
            'papeis' => PapeisEstudio::ATRIBUIVEIS,
        ]);
    }

    public function atribuir(AtribuirPapelEstudioRequest $request): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;
        $utilizador = $this->utilizadorDaEmpresa($empresaId, (int) $request->validated('utilizador_id'));

        // Auto-reparação: garante que o papel existe COM as permissões.
        PapeisEstudio::provisionar($empresaId);

        $utilizador->assignRole(PapeisEstudio::papel($request->validated('papel'), $empresaId));

        return back()->with('sucesso', "Papel {$request->validated('papel')} atribuído a {$utilizador->name}.");
    }

    public function remover(AtribuirPapelEstudioRequest $request): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;
        $utilizador = $this->utilizadorDaEmpresa($empresaId, (int) $request->validated('utilizador_id'));

        $utilizador->removeRole(PapeisEstudio::papel($request->validated('papel'), $empresaId));

        return back()->with('sucesso', "Papel {$request->validated('papel')} removido de {$utilizador->name}.");
    }

    protected function utilizadorDaEmpresa(int $empresaId, int $utilizadorId): User
    {
        return User::query()->where('empresa_id', $empresaId)->findOrFail($utilizadorId);
    }
}
