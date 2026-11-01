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

    public function test_bloqueia_telefone_sem_autorizacao_de_transferencia_internacional(): void
    {
        Queue::fake();
        config([
            'estudiomusica.exigir_autorizacao_apd' => true,
            'estudiomusica.apd_autorizacao_transferencia' => null,
            'estudiomusica.canal_whatsapp' => 'whatsapp_cloud_api',
            'estudiomusica.canal_sms' => 'twilio',
        ]);

        $cliente = new Cliente;
        $cliente->forceFill(['telefone' => '923000000']);

        $resultado = app(NotificacaoEstudioService::class)->enviar(
            $cliente,
            'Assunto',
            'Mensagem'
        );

        $this->assertFalse($resultado);
        Queue::assertNothingPushed();
    }

    public function test_telefone_pode_ser_enfileirado_quando_a_transferencia_esta_autorizada(): void
    {
        Queue::fake();
        config([
            'estudiomusica.exigir_autorizacao_apd' => true,
            'estudiomusica.apd_autorizacao_transferencia' => 'APD-TESTE-123',
            'estudiomusica.canal_whatsapp' => 'whatsapp_cloud_api',
            'estudiomusica.canal_sms' => 'twilio',
        ]);

        $cliente = new Cliente;
        $cliente->forceFill(['telefone' => '923000000']);

        $resultado = app(NotificacaoEstudioService::class)->enviar(
            $cliente,
            'Assunto',
            'Mensagem'
        );

        $this->assertTrue($resultado);
        Queue::assertPushed(EnviarMensagemClienteJob::class);
    }
}
