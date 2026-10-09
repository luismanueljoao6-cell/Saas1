<?php

namespace Modules\EstudioMusica\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Throwable;

/**
 * Faz o envio propriamente dito (WhatsApp + SMS + e-mail) fora do pedido
 * HTTP: uma chamada lenta ou em baixo a um fornecedor externo (cada canal
 * tem timeout de 10s) nunca deve prender o utilizador que acabou de, por
 * exemplo, carregar uma demo ou fazer check-out. Ver
 * Services\NotificacaoEstudioService.
 *
 * Recebe só escalares (telefone, e-mail, texto) e NÃO o model Cliente, de
 * propósito: um worker de queue não tem tenant identificado, e a
 * TenantScope do Core (fail-closed) devolveria vazio ao tentar recarregar
 * o Cliente por id. Cada canal já apanha as suas próprias exceções e
 * devolve false em vez de lançar, por isso a falha de um nunca impede os
 * outros dois.
 */
class EnviarMensagemClienteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ?string $telefone,
        public ?string $email,
        public string $assunto,
        public string $mensagem,
    ) {}

    public function handle(CanalWhatsAppInterface $canalWhatsapp, CanalSmsInterface $canalSms): void
    {
        if ($this->telefone) {
            $canalWhatsapp->enviar($this->telefone, $this->mensagem);
            $canalSms->enviar($this->telefone, $this->mensagem);
        }

        if ($this->email) {
            try {
                Mail::raw($this->mensagem, function ($mail): void {
                    $mail->to($this->email)->subject($this->assunto);
                });
            } catch (Throwable $e) {
                Log::error('EstudioMusica: falha ao enviar e-mail de notificação', [
                    'email' => $this->email,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }
}
