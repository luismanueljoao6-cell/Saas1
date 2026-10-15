<?php

namespace Modules\EstudioMusica\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarSalaEstudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'preco_hora' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'ativa' => ['boolean'],
        ];
    }
}
