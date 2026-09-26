<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        $semIva = round((float) $this->quantidade * (float) $this->preco_unitario, 2);
        $iva = round($semIva * ((float) $this->taxa_iva / 100), 2);

        $this->valor_sem_iva = $semIva;
        $this->valor_iva = $iva;
        $this->valor_total = $semIva + $iva;

        return $this;
    }
}
