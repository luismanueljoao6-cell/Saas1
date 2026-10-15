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
        'agt_codigo',
        'estabelecimento',
        'contingencia',
        'quantidade_autorizada',
        'ultimo_numero_autorizado',
        'agt_criada_em',
    ];

    protected function casts(): array
    {
        return [
            'ano' => 'integer',
            'ultimo_numero' => 'integer',
            'quantidade_autorizada' => 'integer',
            'ultimo_numero_autorizado' => 'integer',
            'agt_criada_em' => 'datetime',
        ];
    }

    public function codigo(): string
    {
        return $this->agt_codigo !== null
            ? "{$this->tipo_documento} {$this->agt_codigo}"
            : "{$this->tipo_documento} {$this->prefixo}{$this->ano}";
    }
}
