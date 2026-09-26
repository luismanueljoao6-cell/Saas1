<?php

namespace Modules\Subscricoes\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;

class Pagamento extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'empresa_id',
        'subscricao_id',
        'gateway',
        'referencia_externa',
        'valor',
        'moeda',
        'estado',
        'payload_bruto',
        'expira_em',
        'confirmado_em',
        'confirmado_por',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'payload_bruto' => 'array',
            'expira_em' => 'datetime',
            'confirmado_em' => 'datetime',
        ];
    }

    public function subscricao(): BelongsTo
    {
        return $this->belongsTo(Subscricao::class);
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    public function estaExpirado(): bool
    {
        return $this->estado === 'pendente' && $this->expira_em !== null && $this->expira_em->isPast();
    }
}
