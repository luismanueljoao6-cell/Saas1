<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\EstudioMusica\Http\Requests\AtualizarPerfilArtistaRequest;
use Modules\EstudioMusica\Services\PerfilArtistaService;
use Modules\Faturacao\Models\Cliente;

class PerfilArtistaController extends Controller
{
    public function update(AtualizarPerfilArtistaRequest $request, int $cliente, PerfilArtistaService $perfilArtista): RedirectResponse
    {
        $cliente = Cliente::findOrFail($cliente);

        $perfilArtista->atualizar($cliente, $request->validated());

        return back()->with('sucesso', 'Ficha do artista atualizada.');
    }
}
