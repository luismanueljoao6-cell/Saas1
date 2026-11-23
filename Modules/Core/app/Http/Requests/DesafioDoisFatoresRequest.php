<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DesafioDoisFatoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Código de 6 dígitos da app ou código de recuperação (XXXXX-XXXXX).
            'codigo' => ['required', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'Indica o código.',
        ];
    }
}
