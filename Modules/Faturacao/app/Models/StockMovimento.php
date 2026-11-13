<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;

class StockMovimento extends Model
{
    use BelongsToTenant;

    protected $table = 'stock_movimentos';

    protected $fillable = [
        'empresa_id', 'produto_id', 'utilizador_id', 'tipo', 'quantidade',
        'saldo_anterior', 'saldo_posterior', 'motivo', 'chave_idempotencia',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:3',
            'saldo_anterior' => 'decimal:3',
            'saldo_posterior' => 'decimal:3',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'utilizador_id');
    }
}
