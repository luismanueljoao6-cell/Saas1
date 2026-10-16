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
 *
 * Marketing: só há consentimento VÁLIDO quando aceita_marketing é true, há
 * data de consentimento e não foi revogado. Ausência de linha, ou de data,
 * significa SEM consentimento (Lei n.º 22/11).
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
        'consentimento_em',
        'consentimento_origem',
        'consentimento_revogado_em',
        'notas',
    ];

    protected $attributes = [
        'aceita_marketing' => false,
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'aceita_marketing' => 'boolean',
            'consentimento_em' => 'datetime',
            'consentimento_revogado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function temConsentimentoValido(): bool
    {
        return $this->aceita_marketing
            && $this->consentimento_em !== null
            && $this->consentimento_revogado_em === null;
    }
}
