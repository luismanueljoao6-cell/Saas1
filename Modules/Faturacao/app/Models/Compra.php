<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\BelongsToTenant;

class Compra extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'empresa_id', 'fornecedor_id', 'numero', 'data_documento',
        'data_vencimento', 'referencia_fornecedor', 'estado', 'subtotal',
        'total_iva', 'total', 'observacoes', 'criado_por', 'recebida_em',
    ];

    protected function casts(): array
    {
        return [
            'data_documento' => 'date',
            'data_vencimento' => 'date',
            'recebida_em' => 'datetime',
            'subtotal' => 'decimal:2',
            'total_iva' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(CompraLinha::class);
    }
}
