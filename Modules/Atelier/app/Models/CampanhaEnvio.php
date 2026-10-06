<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Faturacao\Models\Cliente;

/**
 * Sem empresa_id/BelongsToTenant — o isolamento já vem da Campanha a que
 * pertence (mesmo raciocínio de FaturaLinha/PedidoFoto). A unique
 * constraint (campanha_id, cliente_id) é o que garante idempotência ao
 * reprocessar DispararCampanhaJob.
 */
class CampanhaEnvio extends Model
{
    protected $table = 'atelier_campanha_envios';

    protected $fillable = ['campanha_id', 'cliente_id', 'canal', 'estado', 'erro', 'enviado_em'];

    protected function casts(): array
    {
        return [
            'enviado_em' => 'datetime',
        ];
    }

    public function campanha(): BelongsTo
    {
        return $this->belongsTo(Campanha::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
