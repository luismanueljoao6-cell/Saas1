<?php

namespace Modules\Subscricoes\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Caixa de entrada de webhooks. Deliberadamente SEM BelongsToTenant: o evento
 * chega do gateway antes de existir qualquer tenant; é uma tabela de sistema.
 */
class WebhookEvento extends Model
{
    public const RECEBIDO = 'recebido';

    public const PROCESSADO = 'processado';

    public const REJEITADO = 'rejeitado';

    public const ILEGIVEL = 'ilegivel';

    public const FALHADO = 'falhado';

    public const RESOLVIDO = 'resolvido';

    protected $table = 'webhook_eventos';

    protected $fillable = [
        'gateway',
        'chave_evento',
        'referencia_externa',
        'valor',
        'corpo_bruto',
        'estado',
        'motivo_falha',
        'tentativas',
        'recebimentos',
        'processado_em',
    ];

    protected function casts(): array
    {
        return [
            'tentativas' => 'integer',
            'recebimentos' => 'integer',
            'processado_em' => 'datetime',
        ];
    }
}
