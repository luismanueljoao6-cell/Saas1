<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Produto;

class ArmazemTransferenciaLinha extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_transferencia_linhas';

    protected $fillable = [
        'transferencia_id',
        'empresa_id',
        'produto_id',
        'quantidade',
        'quantidade_recebida',
        'quantidade_em_falta',
        'quantidade_danificada',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:3',
            'quantidade_recebida' => 'decimal:3',
        ];
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(ArmazemTransferencia::class, 'transferencia_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}
