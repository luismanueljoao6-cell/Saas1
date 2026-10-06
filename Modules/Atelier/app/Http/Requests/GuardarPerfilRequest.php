<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarPerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            'historia' => ['nullable', 'string', 'max:5000'],
            'especialidades' => ['nullable', 'string', 'max:2000'],
            'equipa' => ['nullable', 'array'],
            'equipa.*.nome' => ['required_with:equipa', 'string', 'max:255'],
            'equipa.*.funcao' => ['nullable', 'string', 'max:255'],
            'redes_sociais.instagram' => ['nullable', 'string', 'max:255'],
            'redes_sociais.facebook' => ['nullable', 'string', 'max:255'],
            'redes_sociais.whatsapp' => ['nullable', 'string', 'max:255'],
            'publicado' => ['sometimes', 'boolean'],
        ];
    }
}
