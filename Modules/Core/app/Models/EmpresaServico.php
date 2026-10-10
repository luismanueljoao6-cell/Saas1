<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adesão de uma empresa a um serviço (ver Modules\Core\Support\Servico).
 *
 * Não usa BelongsToTenant: é lida antes de haver tenant ambiente (registo,
 * middleware, menu) e o isolamento faz-se sempre através da Empresa.
 */
class EmpresaServico extends Model
{
    protected $fillable = [
        'empresa_id',
        'servico',
        'ativo',
        'aderido_em',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'aderido_em' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
