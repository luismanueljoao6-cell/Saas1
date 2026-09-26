<?php

namespace Modules\Faturacao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarFaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
            'observacoes' => ['nullable', 'string', 'max:1000'],

            'linhas' => ['required', 'array', 'min:1'],
            'linhas.*.produto_id' => ['nullable', 'integer', Rule::exists('produtos', 'id')->where('empresa_id', $empresaId)],
            'linhas.*.descricao' => ['required', 'string', 'max:255'],
            'linhas.*.quantidade' => ['required', 'numeric', 'min:0.001'],
            'linhas.*.preco_unitario' => ['required', 'numeric', 'min:0'],
            'linhas.*.taxa_iva' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
