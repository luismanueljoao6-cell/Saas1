<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtualizarPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        return [
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('empresa_id', $this->user()->empresa_id)],
            'tecido_cor' => ['nullable', 'string', 'max:255'],
            'aviamentos_necessarios' => ['nullable', 'string', 'max:2000'],
            'data_prevista_entrega' => ['nullable', 'date'],
            'prazo_interno' => ['nullable', 'date'],
        ];
    }
}
