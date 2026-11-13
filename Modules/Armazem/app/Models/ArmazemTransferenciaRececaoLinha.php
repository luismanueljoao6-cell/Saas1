<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;

class ArmazemTransferenciaRececaoLinha extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_transferencia_rececao_linhas';

    protected $fillable = [
        'empresa_id',
        'rececao_id',
        'transferencia_linha_id',
        'quantidade_recebida',
        'quantidade_em_falta',
        'quantidade_danificada',
    ];

    protected function casts(): array
    {
        return [
            'quantidade_recebida' => 'decimal:3',
            'quantidade_em_falta' => 'decimal:3',
            'quantidade_danificada' => 'decimal:3',
        ];
    }

    public function rececao(): BelongsTo
    {
        return $this->belongsTo(ArmazemTransferenciaRececao::class, 'rececao_id');
    }

    public function linhaTransferencia(): BelongsTo
    {
        return $this->belongsTo(ArmazemTransferenciaLinha::class, 'transferencia_linha_id');
    }
}
