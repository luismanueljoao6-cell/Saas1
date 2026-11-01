<?php

namespace Modules\Core\Notifications;

use InvalidArgumentException;
use Modules\Core\Notifications\Contracts\NotificationChannel;

class NotificationChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    public function register(NotificationChannel $channel): void
    {
        $this->channels[$channel->identifier()] = $channel;
    }

    public function get(string $identifier): NotificationChannel
    {
        return $this->channels[$identifier]
            ?? throw new InvalidArgumentException("Canal de notificação não registado: {$identifier}");
    }

    public function has(string $identifier): bool
    {
        return isset($this->channels[$identifier]);
    }
}
