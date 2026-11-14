<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;

class CompraLinha extends Model
{
    use BelongsToTenant;

    protected $table = 'compra_linhas';

    protected $fillable = [
        'empresa_id', 'compra_id', 'produto_id', 'descricao', 'quantidade',
        'preco_unitario', 'taxa_iva', 'subtotal', 'valor_iva', 'total',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:3',
            'preco_unitario' => 'decimal:2',
            'taxa_iva' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'valor_iva' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}
