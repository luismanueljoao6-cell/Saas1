<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Faturacao\Http\Requests\GuardarClienteRequest;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\ContaCorrenteClienteService;

class ClienteController extends Controller
{
    public function index(Request $request): View
    {
        $dados = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $clientes = Cliente::query()
            ->when($dados['q'] ?? null, function ($query, string $termo): void {
                $termo = trim($termo);
                $query->where(function ($subquery) use ($termo): void {
                    $subquery->where('nome', 'like', "%{$termo}%")
                        ->orWhere('nif', 'like', "%{$termo}%")
                        ->orWhere('telefone', 'like', "%{$termo}%")
                        ->orWhere('email', 'like', "%{$termo}%");
                });
            })
            ->orderBy('nome')
            ->paginate(20)
            ->withQueryString();

        return view('faturacao::clientes.index', compact('clientes'));
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

    public function store(GuardarClienteRequest $request): RedirectResponse
    {
        Cliente::create([
            ...$request->validated(),
            'empresa_id' => $request->user()->empresa_id,
        ]);

        return back()->with('sucesso', 'Cliente adicionado.');
    }
}
