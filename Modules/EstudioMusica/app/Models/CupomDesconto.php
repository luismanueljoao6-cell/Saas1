<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;

class CupomDesconto extends Model
{
    use BelongsToTenant;

    protected $table = 'cupons_desconto';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'codigo',
        'percentual_desconto',
        'motivo',
        'valido_ate',
        'usado_em',
    ];

    protected function casts(): array
    {
        return [
            'percentual_desconto' => 'decimal:2',
            'valido_ate' => 'date',
            'usado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function valido(): bool
    {
        return $this->usado_em === null && $this->valido_ate->isFuture();
    }

    public function marcarComoUsado(): void
    {
        $this->update(['usado_em' => now()]);
    }
}
