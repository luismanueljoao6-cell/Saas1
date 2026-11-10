<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Exceptions\ResultadoIndeterminadoException;
use Modules\Core\Notifications\NotificationRetryPolicy;
use Modules\EstudioMusica\Notifications\Outbox\EstudioEmailOutboxChannel;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Fase 7 da PR #33: o e-mail do Estúdio passa pela Outbox e as falhas
 * são classificadas (recusa explícita vs resultado desconhecido).
 */
class EstudioEmailOutboxChannelTest extends TestCase
{
    public function test_envio_aceite(): void
    {
        Mail::shouldReceive('raw')->once();

        $resultado = (new EstudioEmailOutboxChannel)->send($this->notificacao());

        $this->assertTrue($resultado->accepted);
    }

    public function test_destinatario_invalido_e_falha_definitiva_sem_enviar(): void
    {
        Mail::shouldReceive('raw')->never();

        $this->expectException(InvalidArgumentException::class);

        (new EstudioEmailOutboxChannel)->send($this->notificacao(email: 'isto-nao-e-email'));
    }

    public function test_payload_sem_mensagem_e_falha_definitiva_sem_enviar(): void
    {
        Mail::shouldReceive('raw')->never();

        $this->expectException(InvalidArgumentException::class);

        (new EstudioEmailOutboxChannel)->send($this->notificacao(['subject' => 'Assunto']));
    }

    public function test_falha_de_ligacao_e_recusa_com_retry(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(
            new TransportException('Connection could not be established with host "smtp.exemplo.test:587": Connection refused (111)')
        );

        $resultado = (new EstudioEmailOutboxChannel)->send($this->notificacao());

        $this->assertFalse($resultado->accepted);
        $this->assertSame('connection_failed', $resultado->errorCode);
        $this->assertSame(NotificationRetryPolicy::RETRY, NotificationRetryPolicy::classify($resultado->errorCode, $resultado->responseCode));
    }

    public function test_credenciais_invalidas_bloqueiam_sem_retry(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(
            new TransportException('Failed to authenticate on SMTP server with username "x".', 535)
        );

        $resultado = (new EstudioEmailOutboxChannel)->send($this->notificacao());

        $this->assertFalse($resultado->accepted);
        $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify($resultado->errorCode, $resultado->responseCode));
    }

    public function test_rejeicao_5xx_do_smtp_e_definitiva(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(
            new TransportException('Expected response code "250" but got code "550", with message "550 Mailbox unavailable".', 550)
        );

        $resultado = (new EstudioEmailOutboxChannel)->send($this->notificacao());

        $this->assertFalse($resultado->accepted);
        $classe = NotificationRetryPolicy::classify($resultado->errorCode, $resultado->responseCode);
        $this->assertTrue(NotificationRetryPolicy::semRetry($classe));
    }

    public function test_resposta_4xx_do_smtp_tem_retry(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(
            new TransportException('Expected response code "250" but got code "451", with message "451 Try again later".', 451)
        );

        $resultado = (new EstudioEmailOutboxChannel)->send($this->notificacao());

        $this->assertFalse($resultado->accepted);
        $classe = NotificationRetryPolicy::classify($resultado->errorCode, $resultado->responseCode);
        $this->assertFalse(NotificationRetryPolicy::semRetry($classe));
    }

    public function test_timeout_a_meio_do_envio_fica_com_resultado_desconhecido(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(
            new TransportException('Connection to "smtp.exemplo.test:587" timed out.')
        );

        $this->expectException(ResultadoIndeterminadoException::class);

        (new EstudioEmailOutboxChannel)->send($this->notificacao());
    }

    public function test_erro_inesperado_depois_do_envio_nao_e_repetido_sozinho(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('listener falhou'));

        $this->expectException(ResultadoIndeterminadoException::class);

        (new EstudioEmailOutboxChannel)->send($this->notificacao());
    }

    private function notificacao(array $payload = ['subject' => 'Assunto', 'message' => 'Olá'], string $email = 'artista@example.test'): NotificationOutbox
    {
        return (new NotificationOutbox)->forceFill(['recipient' => $email, 'payload' => $payload]);
    }
}
