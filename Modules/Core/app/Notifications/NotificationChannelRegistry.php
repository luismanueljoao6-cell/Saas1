<?php

namespace Modules\Core\Notifications;

use Closure;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Notifications\Contracts\NotificationChannel;

class NotificationChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    /** @var array<string, Closure(): NotificationChannel> */
    private array $factories = [];

    public function register(NotificationChannel $channel): void
    {
        $identifier = $channel->identifier();

        unset($this->factories[$identifier]);
        $this->channels[$identifier] = $channel;
    }

    /**
     * Regista um canal sem o construir. Os módulos usam isto no boot():
     * construir o canal aí resolveria o fornecedor configurado (por exemplo,
     * ESTUDIOMUSICA_CANAL_WHATSAPP) e um erro de configuração deitaria abaixo
     * a aplicação inteira. Com o registo preguiçoso, o erro só aparece quando
     * a Outbox tenta usar o canal — e aí fica registado na própria notificação.
     *
     * @param  Closure(): NotificationChannel  $factory
     */
    public function registerLazy(string $identifier, Closure $factory): void
    {
        unset($this->channels[$identifier]);
        $this->factories[$identifier] = $factory;
    }

    public function get(string $identifier): NotificationChannel
    {
        if (isset($this->channels[$identifier])) {
            return $this->channels[$identifier];
        }

        if (isset($this->factories[$identifier])) {
            $channel = ($this->factories[$identifier])();

            if ($channel->identifier() !== $identifier) {
                throw new LogicException("O canal registado como \"{$identifier}\" identifica-se como \"{$channel->identifier()}\".");
            }

            unset($this->factories[$identifier]);

            return $this->channels[$identifier] = $channel;
        }

        throw new InvalidArgumentException("Canal de notificação não registado: {$identifier}");
    }

    public function has(string $identifier): bool
    {
        return isset($this->channels[$identifier]) || isset($this->factories[$identifier]);
    }
}
