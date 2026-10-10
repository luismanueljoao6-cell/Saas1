<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;

class ServicoController extends Controller
{
    public function index(): View
    {
        return view('core::servicos.index', ['empresa' => $this->empresa()]);
    }

    public function atualizar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'servicos' => ['required', 'array', 'min:1'],
            'servicos.*' => ['string', Rule::enum(Servico::class)],
        ], [
            'servicos.required' => 'A empresa tem de ter pelo menos um serviço.',
            'servicos.min' => 'A empresa tem de ter pelo menos um serviço.',
            'servicos.*.enum' => 'O serviço escolhido não é válido.',
        ]);

        $this->empresa()->definirServicos($dados['servicos']);

        return redirect()
            ->route('core.servicos.index')
            ->with('sucesso', 'Serviços atualizados.');
    }

    protected function empresa(): Empresa
    {
        $empresa = auth()->user()->empresa;

        abort_unless($empresa, 404);

        return $empresa;
    }
}
