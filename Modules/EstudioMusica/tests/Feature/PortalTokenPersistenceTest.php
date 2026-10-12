<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Log;
use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
use Modules\EstudioMusica\Notifications\Channels\LogChannel;
use Tests\TestCase;

class PortalTokenPersistenceTest extends TestCase
{
    public function test_o_canal_log_redige_tokens_de_portal_antes_de_registar_mensagens(): void
    {
        $token = 'AbCdEf0123456789AbCdEf0123456789AbCdEf0123456789';
        $mensagem = "Acede ao portal: https://example.test/portal/{$token}";

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $titulo, array $contexto) use ($token): bool {
                return ! str_contains($contexto['mensagem'], $token)
                    && str_contains($contexto['mensagem'], '[TOKEN_REDACTED]');
            });

        (new LogChannel)->enviar('cliente@example.test', $mensagem);
    }

    public function test_job_que_transporta_links_de_portal_exige_payload_encriptado(): void
    {
        $this->assertTrue(is_subclass_of(EnviarMensagemClienteJob::class, ShouldBeEncrypted::class));
    }
}
