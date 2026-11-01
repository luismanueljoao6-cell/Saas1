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
            // A coluna é decimal(10,2): acima de 99.999.999,99 a base de dados recusava com erro 500.
            'preco_hora' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            // sometimes: um formulário que não envia o campo não pode ser lido como "desativar".
            'ativa' => ['sometimes', 'boolean'],
        ];
    }
}
