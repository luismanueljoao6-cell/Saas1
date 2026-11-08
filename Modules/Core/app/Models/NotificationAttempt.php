<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;

class NotificationAttempt extends Model
{
    use BelongsToTenant;

    protected $table = 'notification_attempts';

    protected $fillable = [
        'empresa_id',
        'notification_outbox_id',
        'attempt_number',
        'status',
        'provider',
        'provider_message_id',
        'response_code',
        'error_code',
        'error_message',
        'metadata',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'response_code' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(NotificationOutbox::class, 'notification_outbox_id');
    }

    /**
     * Assinala como abandonadas as tentativas que ficaram «a processar» quando o
     * lease da notificação expirou. O resultado dessas tentativas é DESCONHECIDO
     * (o fornecedor pode ou não ter recebido a mensagem): ficam registadas como
     * tal em vez de parecerem estar ainda a decorrer.
     */
    public static function assinalarAbandonadas(int $empresaId, int $notificationOutboxId): int
    {
        return static::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('notification_outbox_id', $notificationOutboxId)
            ->where('status', 'processing')
            ->update([
                'status' => 'abandoned',
                'error_code' => 'lease_expired',
                'error_message' => 'O processamento foi interrompido antes de terminar; o resultado no fornecedor é desconhecido.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
