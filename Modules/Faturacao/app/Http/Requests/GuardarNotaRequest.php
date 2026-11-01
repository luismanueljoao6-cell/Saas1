<?php

namespace Modules\Faturacao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Rascunho de nota de crédito (sempre sobre uma fatura emitida) ou de débito (com ou sem fatura). */
class GuardarNotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->empresa_id !== null;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;
        $credito = $this->input('tipo') === 'credito';
        // Nota de débito sem IVA (a regra por omissão): a taxa não vem do formulário, é sempre 0.
        $taxaObrigatoria = $credito || (bool) config('faturacao.nota_debito_liquida_iva', false);

        return [
            'tipo' => ['required', Rule::in(['credito', 'debito'])],
            'fatura_id' => [$credito ? 'required' : 'nullable', 'integer', Rule::exists('faturas', 'id')->where('empresa_id', $empresaId)],
            // Sem fatura de origem (só nota de débito) o cliente tem de ser indicado.
            'cliente_id' => ['required_without:fatura_id', 'nullable', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
            // A AGT recebe no máximo 60 caracteres do motivo: não se aceita mais, para não truncar em silêncio.
            'motivo' => ['required', 'string', 'min:3', 'max:60'],

            'linhas' => ['required', 'array', 'min:1', 'max:100'],
            'linhas.*.produto_id' => ['nullable', 'integer', Rule::exists('produtos', 'id')->where('empresa_id', $empresaId)],
            'linhas.*.descricao' => ['required', 'string', 'max:255'],
            'linhas.*.quantidade' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:10000'],
            'linhas.*.preco_unitario' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'linhas.*.taxa_iva' => [$taxaObrigatoria ? 'required' : 'nullable', 'numeric', 'between:0,100'],
            'linhas.*.motivo_isencao' => ['nullable', 'string', Rule::in(array_keys((array) config('faturacao.motivos_isencao', [])))],
        ];
    }
}
