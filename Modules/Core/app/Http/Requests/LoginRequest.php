<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Valida as credenciais. Devolve:
     *  - null: sessão iniciada (conta sem 2FA);
     *  - o User: palavra-passe certa mas a conta tem 2FA. NÃO ficou autenticado;
     *    o controller leva-o ao desafio do segundo fator.
     *
     * Lança ValidationException tanto para credenciais inválidas como para
     * conta desativada, sem pistas sobre contas existentes (a conta desativada
     * só se distingue depois de a palavra-passe estar certa, como antes).
     */
    public function autenticar(): ?User
    {
        $this->garantirNaoBloqueado();

        $aguardaDesafio = null;
        $desativada = false;

        $entrou = Auth::attemptWhen(
            $this->only('email', 'password'),
            function (User $utilizador) use (&$aguardaDesafio, &$desativada): bool {
                if (! $utilizador->ativo) {
                    $desativada = true;

                    return false;
                }

                if ($utilizador->temDoisFatoresAtivos()) {
                    $aguardaDesafio = $utilizador;

                    return false;
                }

                return true;
            },
            $this->boolean('lembrar'),
        );

        if ($desativada) {
            RateLimiter::hit($this->chaveLimitador());

            throw ValidationException::withMessages([
                'email' => 'Esta conta está desativada. Contacta o administrador da tua empresa.',
            ]);
        }

        if (! $entrou && $aguardaDesafio === null) {
            RateLimiter::hit($this->chaveLimitador());

            throw ValidationException::withMessages([
                'email' => 'As credenciais indicadas não são válidas.',
            ]);
        }

        RateLimiter::clear($this->chaveLimitador());

        return $aguardaDesafio;
    }

    protected function garantirNaoBloqueado(): void
    {
        if (! RateLimiter::tooManyAttempts($this->chaveLimitador(), 5)) {
            return;
        }

        event(new Lockout($this));

        $segundos = RateLimiter::availableIn($this->chaveLimitador());

        throw ValidationException::withMessages([
            'email' => "Demasiadas tentativas. Tenta novamente dentro de {$segundos} segundos.",
        ]);
    }

    protected function chaveLimitador(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
