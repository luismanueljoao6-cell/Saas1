<?php

namespace Modules\Subscricoes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class IniciarSubscricaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Só um administrador da empresa pode contratar/mudar de plano.
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        $existe = Rule::exists('planos', 'id');

        // Só planos ativos; ignora o filtro se a tabela não tiver a coluna.
        if (Schema::hasColumn('planos', 'ativo')) {
            $existe->where('ativo', true);
        }

        return [
            'plano_id' => ['required', 'integer', $existe],
        ];
    }
}
