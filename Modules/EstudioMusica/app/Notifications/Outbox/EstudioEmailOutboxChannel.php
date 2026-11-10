<?php

namespace Modules\EstudioMusica\Notifications\Outbox;

use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\Exceptions\ResultadoIndeterminadoException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * E-mail do Estúdio na Outbox partilhada do Core: a chave de idempotência
 * fica na base de dados (e não numa cache) e o resultado é classificado.
 *
 *  - Falha ANTES de o SMTP aceitar (ligação, credenciais, 4xx/5xx): recusa
 *    explícita, segue a NotificationRetryPolicy.
 *  - Timeout, ligação cortada a meio ou qualquer erro depois do envio: a
 *    mensagem pode ter saído; fica «unknown_result», sem retry automático.
 */
final class EstudioEmailOutboxChannel implements NotificationChannel
{
    private const PROVEDOR = 'mail';

    public function identifier(): string
    {
        return 'estudio.email';
    }

    public function send(NotificationOutbox $notification): NotificationSendResult
    {
        $email = trim((string) $notification->recipient);
        $assunto = $notification->payload['subject'] ?? null;
        $mensagem = $notification->payload['message'] ?? null;

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('O destinatário da notificação de e-mail do Estúdio não é válido.');
        }

        if (! is_string($assunto) || trim($assunto) === '' || ! is_string($mensagem) || trim($mensagem) === '') {
            throw new InvalidArgumentException('A notificação do Estúdio não contém payload.subject e payload.message válidos.');
        }

        try {
            Mail::raw($mensagem, function ($mail) use ($email, $assunto): void {
                $mail->to($email)->subject($assunto);
            });
        } catch (TransportExceptionInterface $e) {
            return $this->falhaDeTransporte($e);
        } catch (Throwable $e) {
            // Na dúvida (erro depois do envio, listener, configuração) não se repete sozinho.
            throw new ResultadoIndeterminadoException(
                'Erro inesperado no envio de e-mail; não é possível garantir que a mensagem não saiu: '.mb_substr($e->getMessage(), 0, 300),
                0,
                $e,
            );
        }

        return NotificationSendResult::accepted(self::PROVEDOR);
    }

    private function falhaDeTransporte(TransportExceptionInterface $e): NotificationSendResult
    {
        $mensagem = mb_substr($e->getMessage(), 0, 500);
        $codigo = (int) $e->getCode();

        if ($codigo === 0 && preg_match('/but got code "(\d{3})"/', $mensagem, $m) === 1) {
            $codigo = (int) $m[1];
        }

        if (in_array($codigo, [530, 534, 535], true) || stripos($mensagem, 'Failed to authenticate') !== false) {
            return NotificationSendResult::rejected(self::PROVEDOR, $mensagem, 'provider_auth_failed', $codigo ?: null);
        }

        // Nunca chegou a ligar: seguro repetir.
        if (str_contains($mensagem, 'Connection could not be established')) {
            return NotificationSendResult::rejected(self::PROVEDOR, $mensagem, 'connection_failed');
        }

        if ($codigo >= 500 && $codigo < 600) {
            return NotificationSendResult::rejected(self::PROVEDOR, $mensagem, 'invalid_recipient', $codigo);
        }

        if ($codigo >= 400 && $codigo < 500) {
            return NotificationSendResult::rejected(self::PROVEDOR, $mensagem, 'smtp_temporary', $codigo);
        }

        // Timeout ou ligação cortada a meio: a mensagem pode já ter sido aceite.
        throw new ResultadoIndeterminadoException(
            'Resultado do envio de e-mail indeterminado: '.$mensagem,
            0,
            $e,
        );
    }
}
