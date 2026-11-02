<?php

namespace Modules\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class NotificationStateChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $notificationId,
        public readonly int $empresaId,
        public readonly string $status,
    ) {
    }
}
