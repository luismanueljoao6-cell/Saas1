<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Produto;

class ArmazemStockMovimento extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_stock_movimentos';

    protected $fillable = [
        'empresa_id',
        'armazem_id',
        'produto_id',
        'utilizador_id',
        'tipo',
        'quantidade',
        'saldo_anterior',
        'saldo_posterior',
        'motivo',
        'chave_idempotencia',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:3',
            'saldo_anterior' => 'decimal:3',
            'saldo_posterior' => 'decimal:3',
        ];
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilizador_id');
    }
}
