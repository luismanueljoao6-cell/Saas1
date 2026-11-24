<?php

namespace Modules\Core\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('core::auth.esqueci-palavra-passe');
    }

    /**
     * A resposta é SEMPRE a mesma — a conta existe ou não, está ativa ou não,
     * o pedido foi limitado ou não. Assim o formulário não serve para descobrir
     * que e-mails estão registados. O estado devolvido pelo broker é ignorado
     * de propósito.
     */
    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        // 'ativo' => true: contas desativadas não recebem ligação.
        Password::sendResetLink(['email' => $dados['email'], 'ativo' => true]);

        return redirect()->route('password.request')->with(
            'status',
            'Se existir uma conta ativa com esse e-mail, enviámos a ligação para redefinir a palavra-passe.',
        );
    }
}
