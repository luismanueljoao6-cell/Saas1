<?php

namespace Modules\Core\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * E-mail de redefinição de palavra-passe em português. Propositadamente NÃO
 * é ShouldQueue: o token não deve ficar guardado no payload da fila.
 */
class RedefinirPalavraPasse extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutos = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Redefinir a palavra-passe')
            ->greeting('Olá!')
            ->line('Recebeste este e-mail porque foi pedida a redefinição da palavra-passe da tua conta.')
            ->action('Redefinir palavra-passe', $url)
            ->line("Esta ligação expira dentro de {$minutos} minutos.")
            ->line('Se não pediste a redefinição, não tens de fazer nada: a tua palavra-passe mantém-se.')
            ->salutation('Plataforma de Gestão');
    }
}
