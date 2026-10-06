<?php

namespace Modules\Subscricoes\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Subscricao;

class RenovacaoProximaNotification extends Notification implements ShouldQueue
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
            ->subject('A tua subscrição termina em breve')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("A subscrição da tua empresa termina a {$this->subscricao->termina_em->format('d/m/Y')}.")
            ->line("Para continuares sem interrupção, paga a referência {$this->pagamento->referencia_externa} — {$this->pagamento->valor} {$this->pagamento->moeda} — até {$this->pagamento->expira_em->format('d/m/Y')}.")
            ->line('Os dias que ainda te restarem são somados ao novo período.')
            ->action('Ver planos e pagamentos', url('/planos'));
    }
}
