<?php

namespace Modules\EstudioMusica\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarPerfilArtistaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'nome_artistico' => ['nullable', 'string', 'max:255'],
            'genero_musical' => ['nullable', 'string', 'max:255'],
            'integrantes_banda' => ['nullable', 'array'],
            'integrantes_banda.*' => ['string', 'max:255'],
            'links_redes_sociais' => ['nullable', 'array'],
            'links_redes_sociais.*' => ['nullable', 'string', 'max:255'],
            'data_nascimento' => ['nullable', 'date', 'before:today'],
        ];
    }
}
