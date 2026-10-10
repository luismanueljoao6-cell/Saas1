<?php

namespace Modules\Faturacao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarFaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->empresa_id !== null;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
            'observacoes' => ['nullable', 'string', 'max:1000'],

            'linhas' => ['required', 'array', 'min:1', 'max:100'],
            'linhas.*.produto_id' => ['nullable', 'integer', Rule::exists('produtos', 'id')->where('empresa_id', $empresaId)],
            'linhas.*.descricao' => ['required', 'string', 'max:255'],
            'linhas.*.quantidade' => ['required', 'numeric', 'min:0.001', 'max:10000'],
            'linhas.*.preco_unitario' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'linhas.*.taxa_iva' => [
                'required',
                'numeric',
                function (string $atributo, mixed $valor, \Closure $falhar): void {
                    if (! in_array((float) $valor, config('faturacao.taxas_iva_permitidas'), true)) {
                        $falhar('A taxa de IVA indicada não é permitida.');
                    }
                },
            ],
        ];
    }
}
