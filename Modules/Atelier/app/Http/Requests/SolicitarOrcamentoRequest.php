<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SolicitarOrcamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'contacto' => ['required', 'string', 'max:255'],
            'categoria_interesse' => ['nullable', 'string', 'max:255'],
            'mensagem' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
