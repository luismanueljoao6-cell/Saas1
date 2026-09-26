<?php

namespace Modules\Subscricoes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IniciarSubscricaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Só um administrador da empresa pode contratar/mudar de plano.
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            'plano_id' => ['required', 'integer', 'exists:planos,id'],
        ];
    }
}
