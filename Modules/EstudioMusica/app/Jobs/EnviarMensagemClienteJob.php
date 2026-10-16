<?php

namespace Modules\EstudioMusica\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Modules\EstudioMusica\Support\TransferenciaInternacional;
use Throwable;

/**
 * Faz o envio propriamente dito fora do pedido HTTP. Ver
 * Services\NotificacaoEstudioService.
 *
 * Estratégia de entrega (cada mensagem chega UMA vez por pessoa, não três):
 *  - telefone: WhatsApp; só se falhar, SMS (que é pago) — e só se a transferência
 *    internacional estiver autorizada (Support\TransferenciaInternacional);
 *  - e-mail: enviado sempre que existir.
 * Se NENHUM canal entregar, o job lança exceção e a queue repete (3
 * tentativas com espera crescente) — antes, os canais devolviam `false` em
 * silêncio e a mensagem perdia-se sem retry.
 *
 * Recebe só escalares (telefone, e-mail, texto) e NÃO o model Cliente, de
 * propósito: um worker de queue não tem tenant identificado.
 */
class EnviarMensagemClienteJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public ?string $telefone,
        public ?string $email,
        public string $assunto,
        public string $mensagem,
    ) {}

    /** @return array<int, int> segundos entre tentativas */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(CanalWhatsAppInterface $canalWhatsapp, CanalSmsInterface $canalSms): void
    {
        $tentou = false;
        $entregue = false;

        if ($this->telefone) {
            // Canais que levam o contacto para fora de Angola só com autorização da APD.
            $whatsappPermitido = TransferenciaInternacional::permitida($canalWhatsapp);
            $smsPermitido = TransferenciaInternacional::permitida($canalSms);

            if ($whatsappPermitido || $smsPermitido) {
                $tentou = true;
                $entregue = ($whatsappPermitido && $canalWhatsapp->enviar($this->telefone, $this->mensagem))
                    || ($smsPermitido && $canalSms->enviar($this->telefone, $this->mensagem));
            } else {
                Log::notice('EstudioMusica: envio por telefone bloqueado — falta a autorização da APD para a transferência internacional (ESTUDIOMUSICA_APD_AUTORIZACAO).');
            }
        }

        if ($this->email) {
            $tentou = true;

            try {
                Mail::raw($this->mensagem, function ($mail): void {
                    $mail->to($this->email)->subject($this->assunto);
                });
                $entregue = true;
            } catch (Throwable $e) {
                Log::error('EstudioMusica: falha ao enviar e-mail de notificação', [
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        if ($tentou && ! $entregue) {
            throw new RuntimeException('Nenhum canal conseguiu entregar a mensagem ao cliente.');
        }
    }
}
