<?php

namespace Modules\Core\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Propositadamente NÃO é ShouldQueue: o token não deve ficar no payload da fila.
 */
class ConviteDeUtilizador extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly string $nomeEmpresa,
        public readonly int $validadeDias,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Convite para aderir a {$this->nomeEmpresa}")
            ->greeting('Olá!')
            ->line("Foste convidado(a) para aceder à conta da empresa {$this->nomeEmpresa}.")
            ->action('Aceitar convite', route('core.convite.mostrar', ['token' => $this->token]))
            ->line("Esta ligação expira dentro de {$this->validadeDias} dia(s) e só pode ser usada uma vez.")
            ->line('Se não esperavas este convite, ignora este e-mail.')
            ->salutation('Plataforma de Gestão');
    }
}
