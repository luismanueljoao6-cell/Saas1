<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\BelongsToTenant;

class Produto extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'empresa_id',
        'codigo',
        'nome',
        'unidade',
        'preco_unitario',
        'taxa_iva',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'preco_unitario' => 'decimal:2',
            'taxa_iva' => 'decimal:2',
            'ativo' => 'boolean',
        ];
    }

    public function taxaIvaEfetiva(): float
    {
        return $this->taxa_iva !== null
            ? (float) $this->taxa_iva
            : (float) config('faturacao.taxa_iva_geral');
    }

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }
}
