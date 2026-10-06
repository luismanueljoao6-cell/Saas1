<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgendarProvaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', 'in:primeira,segunda,entrega'],
            'data_hora_agendada' => ['required', 'date', 'after:now'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
