<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;

class StockSaldo extends Model
{
    use BelongsToTenant;

    protected $table = 'stock_saldos';

    protected $fillable = ['empresa_id', 'produto_id', 'quantidade'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:3'];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}
