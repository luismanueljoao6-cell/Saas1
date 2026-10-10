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
    public function __construct(protected EmpresaRepositoryInterface $empresaRepository) {}

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
     * Isolamento entre empresas (o binding de Empresa não tem TenantScope) e
     * restrição ao papel Administrador — igual ao que o FormRequest exige
     * para gravar, para que ninguém veja o que não pode alterar.
     */
    protected function autorizarAcessoAEmpresa(Empresa $empresa): void
    {
        $utilizador = auth()->user();

        abort_unless(
            $utilizador !== null && (int) $empresa->id === (int) $utilizador->empresa_id,
            403,
            'Não tens acesso a esta empresa.'
        );

        abort_unless(
            $utilizador->hasRole('Administrador'),
            403,
            'Só um administrador pode ver as definições da empresa.'
        );
    }
}
