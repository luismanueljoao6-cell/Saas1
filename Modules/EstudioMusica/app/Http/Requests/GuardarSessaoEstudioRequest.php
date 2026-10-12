<?php

namespace Modules\EstudioMusica\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarSessaoEstudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'sala_estudio_id' => ['required', Rule::exists('salas_estudio', 'id')->where('empresa_id', $this->user()->empresa_id)],
            'cliente_id' => ['required', Rule::exists('clientes', 'id')->where('empresa_id', $this->user()->empresa_id)],
            'projeto_musical_id' => ['nullable', Rule::exists('projetos_musicais', 'id')->where('empresa_id', $this->user()->empresa_id)],
            'engenheiro_user_id' => ['nullable', Rule::exists('users', 'id')->where('empresa_id', $this->user()->empresa_id)],
            'tipo_servico' => ['required', Rule::in(array_keys(config('estudiomusica.tipos_servico')))],
            'inicio_previsto' => ['required', 'date'],
            'fim_previsto' => ['required', 'date', 'after:inicio_previsto'],
            'notas' => ['nullable', 'string'],
        ];
    }
}
