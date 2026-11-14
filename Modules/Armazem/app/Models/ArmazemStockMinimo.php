<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Produto;

class ArmazemStockMinimo extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_stock_minimos';

    protected $fillable = [
        'empresa_id',
        'armazem_id',
        'produto_id',
        'quantidade_minima',
    ];

    protected function casts(): array
    {
        return ['quantidade_minima' => 'decimal:3'];
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
