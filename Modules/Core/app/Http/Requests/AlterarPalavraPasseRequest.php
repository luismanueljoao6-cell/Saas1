<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Troca da palavra-passe pelo próprio utilizador autenticado. Exige a atual:
 * uma sessão roubada não chega para lhe tirar a conta.
 */
class AlterarPalavraPasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password_atual' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:password_atual', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'password_atual.required' => 'Indica a tua palavra-passe atual.',
            'password_atual.current_password' => 'A palavra-passe atual não está certa.',
            'password.different' => 'A palavra-passe nova tem de ser diferente da atual.',
        ];
    }
}
