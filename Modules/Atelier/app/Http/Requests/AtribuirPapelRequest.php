<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtribuirPapelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            'utilizador_id' => ['required', 'integer', Rule::exists('users', 'id')->where('empresa_id', $this->user()->empresa_id)],
            'papel' => ['required', 'string', Rule::in([
                config('atelier.papel_secretaria'),
                config('atelier.papel_costureira'),
            ])],
        ];
    }
}
