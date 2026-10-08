<?php

namespace Modules\Subscricoes\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RenovacaoProximaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $afterCommit = true;

    public function __construct(
        protected string $terminaEm,
        protected string $referenciaExterna,
        protected string $valor,
        protected string $moeda,
        protected string $expiraEm,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A tua subscrição termina em breve')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("A subscrição da tua empresa termina a {$this->terminaEm}.")
            ->line(
                "Para continuares sem interrupção, paga a referência ".
                "{$this->referenciaExterna} — {$this->valor} {$this->moeda} — ".
                "até {$this->expiraEm}."
            )
            ->line('Os dias que ainda te restarem são somados ao novo período.')
            ->action('Ver planos e pagamentos', url('/planos'));
    }
}