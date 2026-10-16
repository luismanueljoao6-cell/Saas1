<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Traits\BelongsToTenant;

class AceiteTermos extends Model
{
    use BelongsToTenant;

    protected $table = 'aceites_termos_estudio';

    protected $fillable = ['empresa_id', 'cliente_id', 'termos_estudio_id', 'aceite_em', 'ip_hash', 'user_agent'];

    protected function casts(): array
    {
        return ['aceite_em' => 'datetime'];
    }
}
