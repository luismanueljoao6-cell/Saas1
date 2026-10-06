<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\BelongsToTenant;

class SalaEstudio extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'salas_estudio';

    protected $fillable = [
        'empresa_id',
        'nome',
        'descricao',
        'preco_hora',
        'ativa',
    ];

    protected function casts(): array
    {
        return [
            'preco_hora' => 'decimal:2',
            'ativa' => 'boolean',
        ];
    }

    public function sessoes(): HasMany
    {
        return $this->hasMany(SessaoEstudio::class);
    }

    public function scopeAtivas($query)
    {
        return $query->where('ativa', true);
    }
}
