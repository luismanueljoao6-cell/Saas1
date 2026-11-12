<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;

class Orcamento extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'empresa_id', 'cliente_id', 'fatura_id', 'numero', 'estado', 'data_validade',
        'linhas', 'valor_sem_iva', 'valor_iva', 'valor_total', 'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'data_validade' => 'date',
            'linhas' => 'array',
            'valor_sem_iva' => 'decimal:2',
            'valor_iva' => 'decimal:2',
            'valor_total' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class);
    }
}
