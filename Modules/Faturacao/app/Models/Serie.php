<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Traits\BelongsToTenant;

class Serie extends Model
{
    use BelongsToTenant;

    protected $table = 'series';

    protected $fillable = [
        'empresa_id',
        'tipo_documento',
        'ano',
        'prefixo',
        'ultimo_numero',
    ];

    protected function casts(): array
    {
        return [
            'ano' => 'integer',
            'ultimo_numero' => 'integer',
        ];
    }

    public function codigo(): string
    {
        return "{$this->tipo_documento} {$this->prefixo}{$this->ano}";
    }
}
