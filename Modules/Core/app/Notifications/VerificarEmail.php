<?php

namespace Modules\Core\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * E-mail de verificação em português. A ligação é assinada e temporária
 * (rota verification.verify).
 */
class VerificarEmail extends VerifyEmail
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutos = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Confirma o teu e-mail')
            ->greeting('Olá!')
            ->line('Confirma que este e-mail é teu para proteger o acesso à tua conta.')
            ->action('Confirmar e-mail', $url)
            ->line("Esta ligação expira dentro de {$minutos} minutos.")
            ->line('Se não criaste esta conta, ignora esta mensagem.')
            ->salutation('Plataforma de Gestão');
    }
}
