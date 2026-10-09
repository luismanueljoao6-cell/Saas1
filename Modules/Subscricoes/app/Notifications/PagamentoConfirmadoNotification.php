<?php

namespace Modules\Subscricoes\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PagamentoConfirmadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $afterCommit = true;

    public function __construct(
        protected string $valor,
        protected string $moeda,
        protected string $terminaEm,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pagamento confirmado — subscrição ativa')
            ->greeting("Olá, {$notifiable->name}!")
            ->line('Recebemos a confirmação do teu pagamento.')
            ->line("Valor: {$this->valor} {$this->moeda}")
            ->line("A tua subscrição está ativa até {$this->terminaEm}.")
            ->action('Aceder à plataforma', url('/painel'));
    }
}
