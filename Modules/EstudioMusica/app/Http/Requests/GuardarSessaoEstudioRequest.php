<?php

namespace Modules\EstudioMusica\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarSessaoEstudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Só salas ativas e não apagadas; clientes não apagados.
            'sala_estudio_id' => ['required', Rule::exists('salas_estudio', 'id')
                ->where('empresa_id', $this->user()->empresa_id)->where('ativa', true)->whereNull('deleted_at')],
            'cliente_id' => ['required', Rule::exists('clientes', 'id')
                ->where('empresa_id', $this->user()->empresa_id)->whereNull('deleted_at')],
            // O projeto tem de ser do MESMO cliente da sessão: caso contrário a
            // fatura saía para um cliente e ficava ligada ao projeto de outro.
            // (nem apagado, nem cancelado.)
            'projeto_musical_id' => ['nullable', Rule::exists('projetos_musicais', 'id')
                ->where('empresa_id', $this->user()->empresa_id)->where('cliente_id', $this->input('cliente_id'))
                ->whereNull('deleted_at')->whereNot('estado', 'cancelado')],
            'engenheiro_user_id' => ['nullable', Rule::exists('users', 'id')
                ->where('empresa_id', $this->user()->empresa_id)->where('ativo', true)],
            'tipo_servico' => ['required', Rule::in(array_keys(config('estudiomusica.tipos_servico')))],
            'inicio_previsto' => ['required', 'date'],
            'fim_previsto' => ['required', 'date', 'after:inicio_previsto'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'projeto_musical_id.exists' => 'O projeto escolhido não pertence ao cliente selecionado, está cancelado ou já não existe.',
            'engenheiro_user_id.exists' => 'O engenheiro escolhido não é um membro ativo da equipa.',
        ];
    }
}
