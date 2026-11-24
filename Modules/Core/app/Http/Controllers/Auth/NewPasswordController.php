<?php

namespace Modules\Core\Http\Controllers\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as RegrasPalavraPasse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\User;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('core::auth.redefinir-palavra-passe', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', RegrasPalavraPasse::defaults()],
        ]);

        $estado = Password::reset(
            [
                'email' => $dados['email'],
                'password' => $dados['password'],
                'password_confirmation' => (string) $request->input('password_confirmation'),
                'token' => $dados['token'],
                // Conta desativada não consegue redefinir.
                'ativo' => true,
            ],
            function (User $utilizador, string $palavraPasse): void {
                // O cast 'hashed' do model trata do hash. Mudar o remember_token
                // invalida logins "lembrar-me"; o hash da palavra-passe invalida
                // as sessões abertas (AuthenticateSession no grupo web).
                $utilizador->forceFill([
                    'password' => $palavraPasse,
                    'remember_token' => Str::random(60),
                ])->save();

                // Quem recebeu e usou a ligação no e-mail provou que o controla.
                if (! $utilizador->hasVerifiedEmail()) {
                    $utilizador->markEmailAsVerified();
                }

                // Tokens de API (Sanctum) anteriores deixam de valer.
                $utilizador->tokens()->delete();

                event(new PasswordReset($utilizador));

                Log::info('Palavra-passe redefinida pelo utilizador.', ['utilizador_id' => $utilizador->id]);
            },
        );

        if ($estado !== Password::PASSWORD_RESET) {
            // Mensagem única: não distingue conta inexistente, desativada,
            // token inválido ou expirado.
            throw ValidationException::withMessages([
                'email' => 'Esta ligação de redefinição é inválida ou expirou. Pede uma nova.',
            ]);
        }

        return redirect()->route('core.login')->with('status', __($estado));
    }
}
