<?php

namespace Modules\Subscricoes\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PagamentoConfirmadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $valor,
        protected string $moeda,
        protected string $terminaEm,
    ) {
        // $afterCommit já é declarada pelo trait Queueable (sem tipo): redeclará-la aqui com outro
        // tipo ou valor é erro fatal ao carregar a classe. Assim a notificação só segue depois do commit.
        $this->afterCommit();
    }

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
