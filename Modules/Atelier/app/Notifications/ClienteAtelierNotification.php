<?php

namespace Modules\Atelier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Classe única e reutilizável para todas as notificações por e-mail deste
 * módulo (mudança de estado, lembrete de prova, aniversário, reativação...),
 * ao contrário do padrão "uma classe por evento" que Core/Subscricoes usam
 * (BoasVindasNotification, PagamentoConfirmadoNotification). Decisão
 * deliberada: o Atelier tem muitos gatilhos de notificação com o mesmo
 * formato (assunto + corpo + ação opcional) — sete transições de estado,
 * lembretes, CRM — e criar uma classe quase idêntica para cada um só
 * acrescentaria ficheiros sem acrescentar clareza. Quem decide O QUÊ dizer
 * é sempre o Listener/Job que dispara isto, nunca esta classe.
 */
class ClienteAtelierNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $assunto,
        protected string $mensagem,
        protected ?string $linkAcao = null,
        protected ?string $textoAcao = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->assunto)
            ->line($this->mensagem);

        if ($this->linkAcao) {
            $mail->action($this->textoAcao ?? 'Ver detalhes', $this->linkAcao);
        }

        return $mail;
    }
}
