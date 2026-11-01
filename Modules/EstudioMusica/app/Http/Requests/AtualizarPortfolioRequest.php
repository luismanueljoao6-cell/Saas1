<?php

namespace Modules\EstudioMusica\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marca (ou desmarca) um projeto como destaque da landing pública. Só https: o link vai para
 * um <iframe>/<a> numa página pública (ver ProjetoMusical::incorporacaoPublica()).
 */
class AtualizarPortfolioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'destaque_portfolio' => ['sometimes', 'boolean'],
            'link_publico' => ['nullable', 'url:https', 'max:255', 'required_if:destaque_portfolio,1'],
            // Divulgar o trabalho de um artista exige a autorização dele: o Administrador confirma-a aqui.
            'autorizacao_artista' => ['nullable', 'accepted_if:destaque_portfolio,1'],
        ];
    }

    public function messages(): array
    {
        return [
            'link_publico.required_if' => 'Para aparecer na landing, o projeto precisa de um link público (Spotify, YouTube ou outro, em https).',
            'link_publico.url' => 'O link público tem de ser um endereço https válido.',
            'autorizacao_artista.accepted_if' => 'Confirma que o artista autorizou a divulgação deste projeto.',
        ];
    }
}
