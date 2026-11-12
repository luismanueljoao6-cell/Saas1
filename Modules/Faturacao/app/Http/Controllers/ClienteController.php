<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\ContaCorrenteClienteService;

class ClienteController extends Controller
{
    public function index(): View
    {
        return view('faturacao::clientes.index', [
            'clientes' => Cliente::orderBy('nome')->paginate(20),
        ]);
    }

    public function contaCorrente(Request $request, Cliente $cliente, ContaCorrenteClienteService $contas): View
    {
        $dados = $request->validate([
            'inicio' => ['nullable', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
        ]);

        $inicio = $dados['inicio'] ?? now()->startOfMonth()->toDateString();
        $fim = $dados['fim'] ?? today()->toDateString();

        return view('faturacao::clientes.conta-corrente', [
            'cliente' => $cliente,
            'extrato' => $contas->extrato($cliente, $inicio, $fim),
        ]);
    }

    public function store(\Modules\Faturacao\Http\Requests\GuardarClienteRequest $request): \Illuminate\Http\RedirectResponse
    {
        Cliente::create([
            ...$request->validated(),
            'empresa_id' => $request->user()->empresa_id,
        ]);

        return back()->with('sucesso', 'Cliente adicionado.');
    }
}
