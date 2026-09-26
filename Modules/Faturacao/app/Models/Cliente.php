<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\BelongsToTenant;

class Cliente extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'empresa_id',
        'nome',
        'nif',
        'morada',
        'email',
        'telefone',
    ];

    public function ehConsumidorFinal(): bool
    {
        return empty($this->nif);
    }
}
