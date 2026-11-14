<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;

class ArmazemInventario extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_inventarios';

    protected $fillable = [
        'empresa_id',
        'armazem_id',
        'aberto_por',
        'concluido_por',
        'estado',
        'observacoes',
        'aberto_em',
        'concluido_em',
    ];

    protected function casts(): array
    {
        return [
            'aberto_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class);
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(ArmazemInventarioLinha::class, 'inventario_id');
    }

    public function utilizadorAbertura(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aberto_por');
    }

    public function utilizadorConclusao(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }
}
