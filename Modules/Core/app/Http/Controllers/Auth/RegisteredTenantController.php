<?php

namespace Modules\Core\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Core\Http\Requests\RegistarEmpresaRequest;
use Modules\Core\Services\RegistoEmpresaService;
use Throwable;

class RegisteredTenantController extends Controller
{
    public function __construct(protected RegistoEmpresaService $registoEmpresaService) {}

    public function create(): View
    {
        return view('core::auth.registo');
    }

    public function store(RegistarEmpresaRequest $request): RedirectResponse
    {
        try {
            $utilizador = $this->registoEmpresaService->registar(
                $request->dadosEmpresa(),
                $request->dadosAdministrador(),
                $request->servicosEscolhidos(),
            );
        } catch (Throwable $e) {
            Log::error('Erro no registo de empresa a partir do formulário público', [
                'erro' => $e->getMessage(),
            ]);

            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('erro', 'Não foi possível concluir o registo. Tenta novamente em instantes.');
        }

        Auth::login($utilizador);

        return redirect()->route('core.empresa.editar', $utilizador->empresa_id)
            ->with('sucesso', 'Empresa registada com sucesso! Confirma os teus dados abaixo.');
    }
}
