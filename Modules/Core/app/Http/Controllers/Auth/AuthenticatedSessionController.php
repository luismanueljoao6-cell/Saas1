<?php

namespace Modules\Core\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Core\Http\Requests\LoginRequest;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('core::auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $aguardaDesafio = $request->autenticar();

        if ($aguardaDesafio !== null) {
            // Palavra-passe certa, mas a conta tem 2FA: AINDA NÃO está autenticada.
            // Guarda só o estado do desafio; a sessão abre-se no
            // DesafioDoisFatoresController, depois do código.
            $request->session()->regenerate();
            $request->session()->put(DesafioDoisFatoresController::CHAVE_SESSAO, [
                'id' => $aguardaDesafio->getKey(),
                'lembrar' => $request->boolean('lembrar'),
                'ate' => now()->addMinutes(DesafioDoisFatoresController::VALIDADE_MINUTOS)->timestamp,
            ]);

            return redirect()->route('core.2fa.desafio');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('core.painel'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
