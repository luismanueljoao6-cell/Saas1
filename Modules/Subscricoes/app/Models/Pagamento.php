<?php

namespace Modules\Subscricoes\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;

class Pagamento extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'pagamentos';

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
        'cancelamento_remoto_pendente',
        'cancelamento_remoto_tentativas',
        'cancelamento_remoto_erro',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'payload_bruto' => 'array',
            'expira_em' => 'datetime',
            'confirmado_em' => 'datetime',
            'cancelamento_remoto_pendente' => 'boolean',
        ];
    }

    public function subscricao(): BelongsTo
    {
        return $this->belongsTo(Subscricao::class, 'subscricao_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    /**
     * Entidade Multicaixa a apresentar ao cliente: a devolvida/registada na
     * criação da referência; na falta dela, a configurada para o gateway.
     */
    public function entidade(): ?string
    {
        $entidade = $this->payload_bruto['entity_id'] ?? config("subscricoes.gateways.{$this->gateway}.entity_id");

        return $entidade !== null && $entidade !== '' ? (string) $entidade : null;
    }

    public function estaExpirado(): bool
    {
        return $this->estado === 'pendente' && $this->expira_em !== null && $this->expira_em->isPast();
    }
}
