<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Faturacao\Support\Dinheiro;

class NotaCreditoDebitoLinha extends Model
{
    protected $table = 'notas_credito_debito_linhas';

    protected $fillable = [
        'nota_credito_debito_id',
        'descricao',
        'quantidade',
        'preco_unitario',
        'taxa_iva',
        'valor_sem_iva',
        'valor_iva',
        'valor_total',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:3',
            'preco_unitario' => 'decimal:2',
            'taxa_iva' => 'decimal:2',
            'valor_sem_iva' => 'decimal:2',
            'valor_iva' => 'decimal:2',
            'valor_total' => 'decimal:2',
        ];
    }

    public function notaCreditoDebito(): BelongsTo
    {
        return $this->belongsTo(NotaCreditoDebito::class);
    }

    public function calcularValores(): static
    {
        $v = Dinheiro::calcularLinha($this->quantidade, $this->preco_unitario, $this->taxa_iva);

        $this->valor_sem_iva = $v['sem_iva'];
        $this->valor_iva = $v['iva'];
        $this->valor_total = $v['total'];

        return $this;
    }
}
