<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;

class ArmazemTransferencia extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_transferencias';

    protected $fillable = [
        'empresa_id',
        'armazem_origem_id',
        'armazem_destino_id',
        'criado_por',
        'expedido_por',
        'recebido_por',
        'estado',
        'motivo',
        'observacoes',
        'expedido_em',
        'recebido_em',
    ];

    protected function casts(): array
    {
        return [
            'expedido_em' => 'datetime',
            'recebido_em' => 'datetime',
        ];
    }

    public function origem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_origem_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_destino_id');
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function expedidor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expedido_por');
    }

    public function recebedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recebido_por');
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(ArmazemTransferenciaLinha::class, 'transferencia_id');
    }
}
