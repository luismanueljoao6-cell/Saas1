<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;

class CompraPagamento extends Model
{
    use BelongsToTenant;

    protected $table = 'compra_pagamentos';

    protected $fillable = [
        'empresa_id', 'compra_id', 'valor', 'data_pagamento', 'chave_idempotencia', 'payload_hash',
        'metodo', 'referencia', 'observacoes', 'registado_por',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'data_pagamento' => 'date',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }
}
