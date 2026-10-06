<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvancarEstadoPedidoRequest extends FormRequest
{
    /**
     * Requisito G.3: a Costureira vê/gere o painel de tarefas mas não tem
     * acesso a dados financeiros — por extensão, também não é ela quem
     * marca a entrega final ou o cancelamento de um pedido (ambos com
     * implicações financeiras/de relação com o cliente).
     */
    private const ESTADOS_RESTRITOS_A_GESTAO = ['entregue', 'cancelado'];

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['Administrador', 'Secretária'])) {
            return true;
        }

        if ($user->hasRole('Costureira')) {
            return ! in_array($this->input('novo_estado'), self::ESTADOS_RESTRITOS_A_GESTAO, true);
        }

        return false;
    }

    public function rules(): array
    {
        return [
            'novo_estado' => ['required', 'string', 'in:pendente,em_corte,em_costura,primeira_prova,ajustes,pronto_para_retirada,entregue,cancelado'],
            'motivo_cancelamento' => ['required_if:novo_estado,cancelado', 'nullable', 'string', 'max:1000'],
        ];
    }
}
