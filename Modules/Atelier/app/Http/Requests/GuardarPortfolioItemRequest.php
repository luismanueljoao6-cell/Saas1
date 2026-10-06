<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarPortfolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            'categoria' => ['required', 'string', 'in:vestidos_noiva,ternos,roupas_casuais,ajustes,outro'],
            'titulo' => ['required', 'string', 'max:255'],
            'foto' => ['required', 'image', 'max:4096'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'destaque' => ['sometimes', 'boolean'],
        ];
    }
}
