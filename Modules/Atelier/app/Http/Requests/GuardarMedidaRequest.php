<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarMedidaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $this->user()->empresa_id)],
            'busto' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'cintura' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'quadril' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'ombro_a_ombro' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'cavado' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'coxa' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'comprimento_manga' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'contorno_braco' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'comprimento_perna' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'tornozelo' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'altura' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
