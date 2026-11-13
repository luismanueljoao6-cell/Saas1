<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\BelongsToTenant;

class Fornecedor extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'empresa_id', 'nome', 'nif', 'email', 'telefone', 'morada',
        'pais', 'observacoes', 'ativo',
    ];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}
