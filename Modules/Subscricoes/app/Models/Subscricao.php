<?php

namespace Modules\Subscricoes\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\BelongsToTenant;

/**
 * Fonte de verdade DETALHADA da subscrição. Empresa::estado_subscricao (no
 * módulo Core) é um espelho desnormalizado, mantido em sincronia pelo
 * SubscricaoService via Core\Services\TenantService, para que o middleware
 * VerificarSubscricaoAtiva não precise de fazer join a esta tabela em cada
 * pedido.
 */
class Subscricao extends Model
{
    use BelongsToTenant;
    use HasFactory;

    // Sem isto, o Eloquent adivinha o nome da tabela pluralizando
    // "subscricao" à inglesa ("subscricaos") em vez do nome real da
    // migration ("subscricoes") — português não segue as regras de
    // pluralização em que o Eloquent confia por omissão.
    protected $table = 'subscricoes';

    protected $fillable = [
        'empresa_id',
        'plano_id',
        'estado',
        'inicio_em',
        'termina_em',
        'renovacao_automatica',
        'cancelada_em',
    ];

    protected function casts(): array
    {
        return [
            'inicio_em' => 'datetime',
            'termina_em' => 'datetime',
            'cancelada_em' => 'datetime',
            'renovacao_automatica' => 'boolean',
        ];
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    public function pagamentos(): HasMany
    {
        return $this->hasMany(Pagamento::class);
    }

    public function estaAtiva(): bool
    {
        return $this->estado === 'ativa' && ($this->termina_em === null || $this->termina_em->isFuture());
    }
}
