<?php

namespace Modules\Armazem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Empresa;
use Modules\Core\Traits\BelongsToTenant;

class Armazem extends Model
{
    use BelongsToTenant;

    protected $table = 'armazens';

    protected $fillable = [
        'empresa_id',
        'codigo',
        'nome',
        'descricao',
        'morada',
        'principal',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function saldosStock(): HasMany
    {
        return $this->hasMany(ArmazemStockSaldo::class, 'armazem_id');
    }

    public function movimentosStock(): HasMany
    {
        return $this->hasMany(ArmazemStockMovimento::class, 'armazem_id');
    }
}
