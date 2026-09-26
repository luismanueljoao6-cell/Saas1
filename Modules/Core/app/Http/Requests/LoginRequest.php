<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
     * Tenta autenticar. Lança ValidationException tanto para credenciais
     * inválidas como para empresa/utilizador desativado — propositadamente
     * sem distinguir o motivo exato na mensagem, para não dar pistas a
     * quem tenta adivinhar contas existentes.
     */
    public function autenticar(): void
    {
        $this->garantirNaoBloqueado();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('lembrar'))) {
            RateLimiter::hit($this->chaveLimitador());

            throw ValidationException::withMessages([
                'email' => 'As credenciais indicadas não são válidas.',
            ]);
        }

        if (! Auth::user()->ativo) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Esta conta está desativada. Contacta o administrador da tua empresa.',
            ]);
        }

        RateLimiter::clear($this->chaveLimitador());
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
