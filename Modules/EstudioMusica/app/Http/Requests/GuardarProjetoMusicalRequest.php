<?php

namespace Modules\EstudioMusica\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarProjetoMusicalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'exists:clientes,id'],
            'produtor_user_id' => ['nullable', 'exists:users,id'],
            'engenheiro_user_id' => ['nullable', 'exists:users,id'],
            'nome' => ['required', 'string', 'max:255'],
            'estilo' => ['nullable', 'string', 'max:255'],
            'prazo_entrega' => ['nullable', 'date'],
            'bpm' => ['nullable', 'integer', 'min:1', 'max:400'],
            'tom_base' => ['nullable', 'string', 'max:20'],
            'tipo_cobranca' => ['required', Rule::in(['hora', 'pacote'])],
            'valor_pacote' => ['nullable', 'required_if:tipo_cobranca,pacote', 'numeric', 'min:0'],
            'percentual_sinal' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacoes' => ['nullable', 'string'],
        ];
    }
}
