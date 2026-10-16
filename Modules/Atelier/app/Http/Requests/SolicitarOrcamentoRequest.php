<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SolicitarOrcamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'contacto' => ['required', 'string', 'max:255'],
            'categoria_interesse' => ['nullable', 'string', 'max:255'],
            'mensagem' => ['nullable', 'string', 'max:2000'],
            // Consentimento expresso para tratar os dados (Lei n.º 22/11).
            'consentimento' => ['accepted'],
            // Honeypot: campo invisível que só os robôs preenchem.
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'consentimento.accepted' => 'Tens de aceitar o tratamento dos teus dados para podermos responder ao teu pedido.',
            'website.prohibited' => 'Pedido inválido.',
        ];
    }
}
