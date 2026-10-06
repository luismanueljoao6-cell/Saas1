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
            'sala_estudio_id' => ['required', 'exists:salas_estudio,id'],
            'cliente_id' => ['required', 'exists:clientes,id'],
            'projeto_musical_id' => ['nullable', 'exists:projetos_musicais,id'],
            'engenheiro_user_id' => ['nullable', 'exists:users,id'],
            'tipo_servico' => ['required', Rule::in(array_keys(config('estudiomusica.tipos_servico')))],
            'inicio_previsto' => ['required', 'date'],
            'fim_previsto' => ['required', 'date', 'after:inicio_previsto'],
            'notas' => ['nullable', 'string'],
        ];
    }
}
