<?php

namespace Modules\EstudioMusica\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReagendarSessaoEstudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'inicio_previsto' => ['required', 'date'],
            'fim_previsto' => ['required', 'date', 'after:inicio_previsto'],
        ];
    }
}
