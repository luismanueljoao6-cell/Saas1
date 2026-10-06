<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarEstadoProvaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', 'in:agendada,confirmada,reagendada,concluida,falta'],
            'nova_data_hora_agendada' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
