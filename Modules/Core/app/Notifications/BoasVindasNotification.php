<?php

namespace Modules\Core\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Core\Models\Empresa;

/**
 * Implementa ShouldQueue de propósito: o envio de e-mail nunca deve
 * bloquear o pedido HTTP de registo. Requer um worker de filas a correr
 * (ver Laravel Horizon, recomendado no README) para ser efetivamente
 * assíncrono — sem isso, cai para a fila "sync" por omissão.
 */
class BoasVindasNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Empresa $empresa) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Bem-vindo(a) à plataforma — '.$this->empresa->nome_comercial)
            ->greeting("Olá, {$notifiable->name}!")
            ->line("A empresa {$this->empresa->nome_comercial} foi registada com sucesso.")
            ->line('Está em período experimental. Podes configurar os dados fiscais e convidar a tua equipa a qualquer momento.')
            ->action('Aceder à plataforma', url('/'))
            ->line('Obrigado por escolheres a nossa plataforma.');
    }
}
