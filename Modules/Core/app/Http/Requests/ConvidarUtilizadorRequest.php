<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConvidarUtilizadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            // O nome é só uma sugestão: quem aceita o convite confirma-o.
            'name' => ['nullable', 'string', 'max:255'],
            // Único em toda a plataforma, não só na empresa (ver nota em
            // Modules\Core\Models\User sobre o model não usar BelongsToTenant).
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'administrador' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Já existe uma conta registada com este e-mail.',
        ];
    }
}
