<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;

/**
 * Log de idempotência das automações de marketing — ver a migration para
 * o porquê da unique constraint. Nunca deve ser editado a partir de um
 * controller; só CampanhaMarketingService escreve aqui.
 */
class CampanhaEnviada extends Model
{
    use BelongsToTenant;

    protected $table = 'campanhas_enviadas';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'tipo',
        'referencia',
        'enviada_em',
    ];

    protected function casts(): array
    {
        return [
            'enviada_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
