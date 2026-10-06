<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;

/**
 * Extensão de CRM sobre Modules\Faturacao\Models\Cliente (ver decisão de
 * arquitetura no README: guardado aqui para não alterar o schema do módulo
 * Faturacao). Criado sob demanda — nem todo cliente tem uma linha aqui.
 */
class ClienteCrm extends Model
{
    use BelongsToTenant;

    protected $table = 'atelier_clientes_crm';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'data_nascimento',
        'aceita_marketing',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'aceita_marketing' => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
