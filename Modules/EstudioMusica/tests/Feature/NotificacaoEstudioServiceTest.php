<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

class NotificacaoEstudioServiceTest extends TestCase
{
    public function test_nao_enfileira_notificacao_quando_cliente_nao_tem_contactos(): void
    {
        Queue::fake();

        $cliente = new Cliente;

        $resultado = app(NotificacaoEstudioService::class)->enviar(
            $cliente,
            'Assunto',
            'Mensagem'
        );

        $this->assertFalse($resultado);
        Queue::assertNothingPushed();
    }

    public function test_nao_enfileira_notificacao_para_cliente_apagado(): void
    {
        Queue::fake();

        $cliente = new Cliente;
        $cliente->forceFill(['deleted_at' => now(), 'email' => 'cliente@example.com']);

        $resultado = app(NotificacaoEstudioService::class)->enviar(
            $cliente,
            'Assunto',
            'Mensagem'
        );

        $this->assertFalse($resultado);
        Queue::assertNothingPushed();
    }

    public function test_confirma_apenas_o_enfileiramento_quando_existe_email(): void
    {
        Queue::fake();

        $cliente = new Cliente;
        $cliente->forceFill(['email' => 'cliente@example.com']);

        $resultado = app(NotificacaoEstudioService::class)->enviar(
            $cliente,
            'Assunto',
            'Mensagem'
        );

        $this->assertTrue($resultado);
        Queue::assertPushed(EnviarMensagemClienteJob::class, fn (EnviarMensagemClienteJob $job): bool => $job->email === 'cliente@example.com');
    }
}
