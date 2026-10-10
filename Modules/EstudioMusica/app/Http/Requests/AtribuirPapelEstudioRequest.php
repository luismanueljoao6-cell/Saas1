<?php

namespace Modules\EstudioMusica\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\EstudioMusica\Support\PapeisEstudio;

class AtribuirPapelEstudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            'utilizador_id' => ['required', 'integer', Rule::exists('users', 'id')->where('empresa_id', $this->user()->empresa_id)],
            'papel' => ['required', 'string', Rule::in(PapeisEstudio::ATRIBUIVEIS)],
        ];
    }
}
