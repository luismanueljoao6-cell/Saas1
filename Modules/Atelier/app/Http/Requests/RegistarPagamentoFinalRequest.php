<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sem campo 'valor' de propósito — o valor é sempre Pedido::saldoEmFalta(),
 * calculado no servidor a partir da fatura já emitida. Confiar num valor
 * vindo do pedido HTTP para um documento fiscal seria exatamente o erro que
 * o comentário em FaturaLinha::calcularValores() já avisa para não cometer.
 */
class RegistarPagamentoFinalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        return [
            'meio_pagamento' => ['required', 'string', 'in:dinheiro,transferencia,multicaixa,outro'],
        ];
    }
}
