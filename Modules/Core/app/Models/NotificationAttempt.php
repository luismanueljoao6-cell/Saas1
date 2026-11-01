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
}
