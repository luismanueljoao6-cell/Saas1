<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\BelongsToTenant;

class TermosEstudio extends Model
{
    use BelongsToTenant;

    protected $table = 'termos_estudio';

    protected $fillable = ['empresa_id', 'versao', 'titulo', 'conteudo', 'publicado_em'];

    protected function casts(): array
    {
        return ['publicado_em' => 'datetime'];
    }

    public function aceites(): HasMany
    {
        return $this->hasMany(AceiteTermos::class, 'termos_estudio_id');
    }
}
