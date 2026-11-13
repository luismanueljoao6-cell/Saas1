<?php

namespace Modules\\Armazem\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use Modules\\Core\\Models\\Empresa;
use Modules\\Core\\Traits\\BelongsToTenant;

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
}
