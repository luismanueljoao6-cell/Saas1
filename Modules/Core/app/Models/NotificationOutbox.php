<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Traits\BelongsToTenant;

class NotificationOutbox extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const STATUS_CANCELLED = 'cancelled';

    /** O fornecedor pode ou não ter recebido a mensagem: sem reenvio automático até haver reconciliação ou decisão humana. */
    public const STATUS_UNKNOWN_RESULT = 'unknown_result';

    protected $table = 'notification_outbox';

    protected $fillable = [
        'empresa_id',
        'uuid',
        'idempotency_key',
        'channel',
        'provider',
        'recipient',
        'payload',
        'status',
        'attempts',
        'max_attempts',
        'available_at',
        'locked_at',
        'lock_token',
        'provider_message_id',
        'provider_response',
        'accepted_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'last_error',
    ];

    protected $hidden = [
        'recipient',
        'payload',
        'provider_response',
        'lock_token',
    ];

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'payload' => 'encrypted:array',
            'provider_response' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'accepted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $notification->uuid ??= (string) Str::uuid();
            $notification->status ??= self::STATUS_PENDING;
            $notification->available_at ??= now();
        });
    }

    public function attemptsHistory(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class, 'notification_outbox_id')
            ->orderBy('attempt_number');
    }

    /**
     * O fornecedor já recebeu (aceitou) esta mensagem — ainda que o estado possa
     * evoluir depois (entregue, lida). Nunca pode voltar a ser enviada, mesmo que
     * «accepted» não seja um estado terminal.
     */
    public function foiEntregueAoFornecedor(): bool
    {
        return in_array($this->status, [
            self::STATUS_ACCEPTED,
            self::STATUS_DELIVERED,
            self::STATUS_READ,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_READ,
            self::STATUS_FAILED,
            self::STATUS_DEAD_LETTER,
            self::STATUS_CANCELLED,
            self::STATUS_UNKNOWN_RESULT,
        ], true);
    }
}
