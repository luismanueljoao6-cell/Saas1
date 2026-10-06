<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\EstudioMusica\Http\Requests\GuardarSalaEstudioRequest;
use Modules\EstudioMusica\Models\SalaEstudio;

class SalaEstudioController extends Controller
{
    public function index(): View
    {
        return view('estudiomusica::salas.index', [
            'salas' => SalaEstudio::orderBy('nome')->paginate(20),
        ]);
    }

    public function store(GuardarSalaEstudioRequest $request): RedirectResponse
    {
        SalaEstudio::create($request->validated());

        return back()->with('sucesso', 'Sala adicionada.');
    }

    /**
     * Recebe o id (não binding implícito de model) e resolve com
     * findOrFail() aqui dentro, de propósito: a esta altura o middleware
     * 'tenant' já correu de certeza (middleware corre sempre antes do
     * corpo do método), o que NÃO está garantido para a substituição
     * implícita de bindings, cuja posição na pipeline depende de
     * $middlewarePriority do Laravel e não foi verificada neste projeto
     * para middleware de tenant customizado. Mesmo padrão em todos os
     * outros controllers deste módulo — ver nota igual em
     * SessaoEstudioController.
     */
    public function update(GuardarSalaEstudioRequest $request, int $sala): RedirectResponse
    {
        $sala = SalaEstudio::findOrFail($sala);

        $sala->update($request->validated());

        return back()->with('sucesso', 'Sala atualizada.');
    }
}
