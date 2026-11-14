<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;

class ArmazemTransferenciaRececao extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_transferencia_rececoes';

    protected $fillable = [
        'empresa_id',
        'transferencia_id',
        'chave_idempotencia',
        'payload_hash',
        'utilizador_id',
        'finalizada',
    ];

    protected function casts(): array
    {
        return [
            'finalizada' => 'boolean',
        ];
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(ArmazemTransferencia::class, 'transferencia_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilizador_id');
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(ArmazemTransferenciaRececaoLinha::class, 'rececao_id');
    }
}
