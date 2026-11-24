<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ações sensíveis da conta (ativar, desativar, regenerar códigos) pedem a
 * palavra-passe atual: uma sessão roubada não chega para trocar o 2FA.
 */
class ConfirmarPalavraPasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Indica a tua palavra-passe para continuar.',
            'password.current_password' => 'A palavra-passe indicada não está certa.',
        ];
    }
}
