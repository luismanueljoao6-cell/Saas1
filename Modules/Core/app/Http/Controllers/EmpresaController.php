<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Core\Http\Requests\AtualizarEmpresaRequest;
use Modules\Core\Models\Empresa;
use Modules\Core\Repositories\Contracts\EmpresaRepositoryInterface;
use Throwable;

class EmpresaController extends Controller
{
    public function __construct(protected EmpresaRepositoryInterface $empresaRepository)
    {
    }

    public function editar(Empresa $empresa): View
    {
        $this->autorizarAcessoAEmpresa($empresa);

        return view('core::empresas.definicoes', ['empresa' => $empresa]);
    }

    public function atualizar(AtualizarEmpresaRequest $request, Empresa $empresa): RedirectResponse
    {
        try {
            $this->empresaRepository->atualizar($empresa, $request->validated());
        } catch (Throwable $e) {
            Log::error('Falha ao atualizar definições da empresa', [
                'empresa_id' => $empresa->id,
                'erro' => $e->getMessage(),
            ]);

            return back()->withInput()->with('erro', 'Não foi possível guardar as alterações. Tenta novamente.');
        }

        return back()->with('sucesso', 'Dados da empresa atualizados.');
    }

    /**
     * Segunda barreira de segurança além da TenantScope: mesmo que a query
     * do route model binding já esteja implicitamente filtrada pelo tenant
     * atual, confirmamos aqui explicitamente — nunca confiar numa única
     * camada quando se trata de isolamento de dados entre empresas.
     */
    protected function autorizarAcessoAEmpresa(Empresa $empresa): void
    {
        abort_unless(
            $empresa->id === auth()->user()?->empresa_id,
            403,
            'Não tens acesso a esta empresa.'
        );
    }
}
