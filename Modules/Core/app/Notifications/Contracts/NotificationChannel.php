<?php

namespace Modules\Core\Notifications\Contracts;

use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\DTO\NotificationSendResult;

interface NotificationChannel
{
    public function identifier(): string;

    public function send(NotificationOutbox $notification): NotificationSendResult;
}
