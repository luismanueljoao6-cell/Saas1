<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
            'medida_id' => ['nullable', 'integer', Rule::exists('atelier_medidas', 'id')->where('empresa_id', $empresaId)],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('empresa_id', $empresaId)],
            'tipo_servico' => ['required', 'string', 'in:confecao_medida,ajuste_conserto,restauracao,figurino'],
            'descricao' => ['required', 'string', 'max:2000'],
            'tecido_cor' => ['nullable', 'string', 'max:255'],
            'aviamentos_necessarios' => ['nullable', 'string', 'max:2000'],
            'data_prevista_entrega' => ['nullable', 'date'],
            'prazo_interno' => ['nullable', 'date'],
            'valor_orcamento' => ['required', 'numeric', 'min:0'],
            'percentual_sinal' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'materiais' => ['nullable', 'array'],
            'materiais.*.descricao' => ['required_with:materiais', 'string', 'max:255'],
            'materiais.*.quantidade' => ['required_with:materiais', 'numeric', 'min:0.01'],
            'materiais.*.valor_unitario' => ['required_with:materiais', 'numeric', 'min:0'],
            'fotos.*' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
