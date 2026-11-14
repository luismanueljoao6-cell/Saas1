<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Produto;

class ArmazemInventarioLinha extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_inventario_linhas';

    protected $fillable = [
        'empresa_id',
        'inventario_id',
        'produto_id',
        'quantidade_sistema',
        'quantidade_contada',
        'diferenca',
    ];

    protected function casts(): array
    {
        return [
            'quantidade_sistema' => 'decimal:3',
            'quantidade_contada' => 'decimal:3',
            'diferenca' => 'decimal:3',
        ];
    }

    public function inventario(): BelongsTo
    {
        return $this->belongsTo(ArmazemInventario::class, 'inventario_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}
