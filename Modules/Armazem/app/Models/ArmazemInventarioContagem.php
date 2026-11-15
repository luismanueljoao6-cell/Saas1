<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;

class ArmazemInventarioContagem extends Model
{
    use BelongsToTenant;

    protected $table = 'armazem_inventario_contagens';

    protected $fillable = [
        'empresa_id',
        'inventario_id',
        'linha_id',
        'utilizador_id',
        'quantidade_sistema',
        'quantidade_contada',
        'diferenca',
        'registado_em',
    ];

    protected function casts(): array
    {
        return [
            'quantidade_sistema' => 'decimal:3',
            'quantidade_contada' => 'decimal:3',
            'diferenca' => 'decimal:3',
            'registado_em' => 'datetime',
        ];
    }

    public function linha(): BelongsTo
    {
        return $this->belongsTo(ArmazemInventarioLinha::class, 'linha_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilizador_id');
    }
}
