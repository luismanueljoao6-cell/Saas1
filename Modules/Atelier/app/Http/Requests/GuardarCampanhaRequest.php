<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarCampanhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'canal' => ['required', 'string', 'in:mail,whatsapp,sms'],
            'segmento' => ['required', 'string', 'in:todos,aniversariantes_mes,inativos'],
            'assunto' => ['nullable', 'string', 'max:255'],
            'mensagem' => ['required', 'string', 'max:2000'],
            'agendada_para' => ['nullable', 'date', 'after:now'],
        ];
    }
}
