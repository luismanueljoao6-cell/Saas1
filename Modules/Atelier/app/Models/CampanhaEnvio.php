<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\NotificationOutbox;
use Modules\Faturacao\Models\Cliente;

/**
 * Sem empresa_id/BelongsToTenant — o isolamento já vem da Campanha a que
 * pertence (mesmo raciocínio de FaturaLinha/PedidoFoto). A unique
 * constraint (campanha_id, cliente_id) é o que garante idempotência ao
 * reprocessar DispararCampanhaJob.
 */
class CampanhaEnvio extends Model
{
    public const ESTADO_PENDENTE = 'pendente';

    public const ESTADO_ACEITE = 'aceite';

    public const ESTADO_ENVIADO = 'enviado';

    public const ESTADO_FALHOU = 'falhou';

    /** A Outbox não sabe se o fornecedor recebeu a mensagem: nunca é reenviada sem decisão humana. */
    public const ESTADO_INCERTO = 'incerto';

    public const ERRO_INCERTO = 'Resultado desconhecido: o fornecedor pode ter recebido a mensagem. Não é reenviada automaticamente.';

    protected $table = 'atelier_campanha_envios';

    protected $fillable = ['campanha_id', 'cliente_id', 'canal', 'estado', 'tentativa', 'erro', 'enviado_em', 'notification_outbox_id'];

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

    public function notificationOutbox(): BelongsTo
    {
        return $this->belongsTo(NotificationOutbox::class, 'notification_outbox_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
