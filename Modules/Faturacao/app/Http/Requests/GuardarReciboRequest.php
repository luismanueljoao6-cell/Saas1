<?php

namespace Modules\Faturacao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Recibo de uma fatura emitida (o recibo de adiantamento não tem interface: a AGT ainda não o suporta). */
class GuardarReciboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->empresa_id !== null;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            'fatura_id' => ['required', 'integer', Rule::exists('faturas', 'id')->where('empresa_id', $empresaId)],
            'valor' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'meio_pagamento' => ['required', 'string', Rule::in(array_keys((array) config('faturacao.meios_pagamento', [])))],
        ];
    }
}
