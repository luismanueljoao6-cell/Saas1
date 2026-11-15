<?php

namespace Modules\Faturacao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Faturacao\Support\ValidacaoLinhas;

/** Regras únicas para criar e atualizar um orçamento (antes duplicadas no controller). */
class GuardarOrcamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->empresa !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $empresaId = $this->user()->empresa->id;

        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
            'data_validade' => ['nullable', 'date', 'after_or_equal:today'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
            'linhas' => ['required', 'array', 'min:1', 'max:100'],
            'linhas.*.produto_id' => ['nullable', 'integer', Rule::exists('produtos', 'id')->where('empresa_id', $empresaId)],
            'linhas.*.descricao' => ['required', 'string', 'max:255'],
            'linhas.*.quantidade' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:10000'],
            'linhas.*.preco_unitario' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'linhas.*.taxa_iva' => ['required', 'numeric'],
            'linhas.*.motivo_isencao' => ['nullable', 'string', Rule::in($this->codigosMotivosIsencao())],
            'linhas.*.operation_type' => ['nullable', 'string', Rule::in(ValidacaoLinhas::TIPOS_OPERACAO_AGT)],
        ];
    }

    /** @return list<string> */
    protected function codigosMotivosIsencao(): array
    {
        return array_keys(array_filter(
            (array) config('faturacao.motivos_isencao', []),
            fn (array $motivo) => in_array($motivo['tipo'] ?? null, ['ISE', 'NS'], true),
        ));
    }
}
