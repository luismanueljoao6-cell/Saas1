<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pública — a proteção de acesso é a assinatura da URL (middleware
 * 'signed'), não um papel de utilizador autenticado.
 */
class ReagendarProvaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nova_data_hora' => ['required', 'date', 'after:now'],
        ];
    }
}
