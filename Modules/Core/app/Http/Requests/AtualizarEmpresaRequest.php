<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtualizarEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $utilizador = $this->user();

        // Só um administrador da própria empresa pode editar as definições.
        return $utilizador !== null
            && $utilizador->empresa_id === $this->route('empresa')?->id
            && $utilizador->hasRole('Administrador');
    }

    public function rules(): array
    {
        $empresaId = $this->route('empresa')?->id;

        return [
            'nome_comercial' => ['required', 'string', 'max:255'],
            'nome_legal' => ['nullable', 'string', 'max:255'],
            'nif' => ['required', 'string', 'min:9', 'max:20', Rule::unique('empresas', 'nif')->ignore($empresaId)],
            'regime_fiscal' => ['nullable', 'string', 'max:100'],
            'morada' => ['nullable', 'string', 'max:255'],
            'municipio' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
