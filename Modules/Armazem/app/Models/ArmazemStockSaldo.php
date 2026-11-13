<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Produto;

class ArmazemStockSaldo extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_stock_saldos';

    protected $fillable = [
        'empresa_id',
        'armazem_id',
        'produto_id',
        'quantidade',
    ];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:3'];
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}
