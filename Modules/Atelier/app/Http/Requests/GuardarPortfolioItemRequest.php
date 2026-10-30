<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarPortfolioItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            'categoria' => ['required', 'string', Rule::in(array_keys(config('atelier.categorias_portfolio')))],
            'titulo' => ['required', 'string', 'max:255'],
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'destaque' => ['sometimes', 'boolean'],
        ];
    }
}
