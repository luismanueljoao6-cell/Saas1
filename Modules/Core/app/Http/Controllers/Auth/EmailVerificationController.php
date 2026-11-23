<?php

namespace Modules\Core\Http\Controllers\Auth;

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Estas rotas usam só 'auth' (sem 'tenant'): o middleware 'tenant' redireciona
 * para aqui quem tem o e-mail por verificar, e criaria um ciclo.
 */
class EmailVerificationController extends Controller
{
    public function aviso(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('core.painel');
        }

        return view('core::auth.verificar-email');
    }

    public function verificar(EmailVerificationRequest $request): RedirectResponse
    {
        // O pedido só chega aqui com ligação assinada e com id/hash do próprio
        // utilizador autenticado (EmailVerificationRequest::authorize).
        $request->fulfill();

        return redirect()->route('core.painel')->with('sucesso', 'E-mail verificado com sucesso.');
    }

    public function reenviar(Request $request): RedirectResponse
    {
        $utilizador = $request->user();

        if ($utilizador->hasVerifiedEmail()) {
            return redirect()->route('core.painel');
        }

        $utilizador->sendEmailVerificationNotification();

        return back()->with('status', 'Enviámos uma nova ligação de verificação para o teu e-mail.');
    }
}
