<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmarDoisFatoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:16'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'Escreve o código de 6 dígitos da tua app autenticadora.',
        ];
    }
}
