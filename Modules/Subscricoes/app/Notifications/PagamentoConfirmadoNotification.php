<?php

namespace Modules\Subscricoes\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Subscricao;

class PagamentoConfirmadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Pagamento $pagamento, protected Subscricao $subscricao)
    {
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
            ->line("Valor: {$this->pagamento->valor} {$this->pagamento->moeda}")
            ->line("A tua subscrição está ativa até {$this->subscricao->termina_em->format('d/m/Y')}.")
            ->action('Aceder à plataforma', url('/painel'));
    }
}
