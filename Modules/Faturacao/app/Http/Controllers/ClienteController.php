<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Faturacao\Http\Requests\GuardarClienteRequest;
use Modules\Faturacao\Models\Cliente;

class ClienteController extends Controller
{
    public function index(): View
    {
        return view('faturacao::clientes.index', [
            'clientes' => Cliente::orderBy('nome')->paginate(20),
        ]);
    }

    public function store(GuardarClienteRequest $request): RedirectResponse
    {
        Cliente::create([
            ...$request->validated(),
            'empresa_id' => $request->user()->empresa_id,
        ]);

        return back()->with('sucesso', 'Cliente adicionado.');
    }
}
