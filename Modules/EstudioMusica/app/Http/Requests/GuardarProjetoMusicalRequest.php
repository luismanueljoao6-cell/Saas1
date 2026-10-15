<?php

namespace Modules\EstudioMusica\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarProjetoMusicalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'cliente_id' => ['required', Rule::exists('clientes', 'id')->where('empresa_id', $this->user()->empresa_id)->whereNull('deleted_at')],
            'produtor_user_id' => ['nullable', Rule::exists('users', 'id')->where('empresa_id', $this->user()->empresa_id)->where('ativo', true)],
            'engenheiro_user_id' => ['nullable', Rule::exists('users', 'id')->where('empresa_id', $this->user()->empresa_id)->where('ativo', true)],
            'nome' => ['required', 'string', 'max:255'],
            'estilo' => ['nullable', 'string', 'max:255'],
            'prazo_entrega' => ['nullable', 'date', 'after_or_equal:today'],
            'bpm' => ['nullable', 'integer', 'min:1', 'max:400'],
            'tom_base' => ['nullable', 'string', 'max:20'],
            'tipo_cobranca' => ['required', Rule::in(['hora', 'pacote'])],
            // Um pacote a 0 Kz nunca poderia gerar sinal (o serviço recusa-o) — falha logo aqui.
            'valor_pacote' => ['nullable', 'required_if:tipo_cobranca,pacote', 'numeric', 'min:0', 'max:999999999999', Rule::when($this->input('tipo_cobranca') === 'pacote', ['gt:0'])],
            'percentual_sinal' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacoes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
