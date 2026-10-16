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

        $prazoInterno = ['nullable', 'date', 'after_or_equal:today'];

        // before_or_equal contra outro campo ausente falha a validação — só se aplica quando há data de entrega.
        if ($this->filled('data_prevista_entrega')) {
            $prazoInterno[] = 'before_or_equal:data_prevista_entrega';
        }

        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
            // A medida tem de ser do MESMO cliente do pedido (não de outro cliente da empresa).
            'medida_id' => [
                'nullable',
                'integer',
                Rule::exists('atelier_medidas', 'id')
                    ->where('empresa_id', $empresaId)
                    ->where('cliente_id', (int) $this->input('cliente_id')),
            ],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('empresa_id', $empresaId)],
            'tipo_servico' => ['required', 'string', Rule::in(array_keys(config('atelier.tipos_servico')))],
            'descricao' => ['required', 'string', 'max:2000'],
            'tecido_cor' => ['nullable', 'string', 'max:255'],
            'aviamentos_necessarios' => ['nullable', 'string', 'max:2000'],
            'data_prevista_entrega' => ['nullable', 'date', 'after_or_equal:today'],
            'prazo_interno' => $prazoInterno,
            'valor_orcamento' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'percentual_sinal' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'materiais' => ['nullable', 'array', 'max:50'],
            'materiais.*.descricao' => ['required_with:materiais', 'string', 'max:255'],
            'materiais.*.quantidade' => ['required_with:materiais', 'numeric', 'min:0.01', 'max:100000'],
            'materiais.*.valor_unitario' => ['required_with:materiais', 'numeric', 'min:0', 'max:999999999.99'],
            'fotos' => ['nullable', 'array', 'max:10'],
            'fotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
