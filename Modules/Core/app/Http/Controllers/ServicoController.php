<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantProvisionerRegistry;
use Modules\Core\Support\Servico;
use Throwable;

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

        $empresa = $this->empresa();

        try {
            // Tudo ou nada: se um provisionador falhar, a adesão também é desfeita.
            DB::transaction(function () use ($empresa, $dados) {
                $empresa->definirServicos($dados['servicos']);

                // Cria/repara papéis e permissões dos serviços agora ativos.
                app(TenantProvisionerRegistry::class)->provision(
                    $empresa->id,
                    $empresa->servicosAderidos()->map(fn (Servico $servico) => $servico->value)->all(),
                );
            });
        } catch (Throwable $e) {
            Log::error('Falha ao atualizar os serviços da empresa', [
                'empresa_id' => $empresa->id,
                'servicos' => $dados['servicos'],
                'erro' => $e->getMessage(),
                'causa' => $e->getPrevious()?->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('erro', 'Não foi possível atualizar os serviços. Nada foi alterado; tenta novamente. Se voltar a falhar, contacta o suporte.');
        }

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
