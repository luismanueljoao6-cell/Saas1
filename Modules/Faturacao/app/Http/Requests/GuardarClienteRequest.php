<?php

namespace Modules\Faturacao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            'nome' => ['required', 'string', 'max:255'],
            // NIF opcional (consumidor final); confirma o formato exato
            // junto da AGT antes de o tornares mais restritivo — ver
            // README do módulo Core sobre a mesma cautela.
            'nif' => ['nullable', 'string', 'max:20', Rule::unique('clientes', 'nif')->where('empresa_id', $empresaId)],
            'morada' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
