<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Notifications\Outbox\AtelierSmsOutboxChannel;
use Modules\Atelier\Notifications\Outbox\AtelierWhatsAppOutboxChannel;
use Modules\Atelier\Services\Notificacoes\SmsCanal;
use Modules\Atelier\Services\Notificacoes\WhatsAppCanal;
use Modules\Atelier\Support\TransferenciaInternacional;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\NotificationRetryPolicy;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;
use Tests\TestCase;

/**
 * WhatsApp e SMS levam o contacto do cliente para fora de Angola: sem a
 * autorização da APD (ou sem a exigência desligada de propósito) nada sai.
 */
class TransferenciaInternacionalAtelierTest extends TestCase
{
    use RefreshDatabase;

    private int $empresaId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'atelier.whatsapp.base_url' => 'https://graph.example.test/v1',
            'atelier.whatsapp.token' => 'token-teste',
            'atelier.whatsapp.numero_remetente_id' => '123',
            'atelier.sms.base_url' => 'https://sms.example.test/enviar',
            'atelier.sms.api_key' => 'chave-teste',
            'atelier.exigir_autorizacao_apd' => true,
            'atelier.apd_autorizacao_transferencia' => null,
        ]);

        $admin = app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Atelier APD', 'nif' => '500003001'],
            ['name' => 'Admin', 'email' => 'admin500003001@example.test', 'password' => 'Senha-segura-123'],
            ['atelier'],
        );
        $this->empresaId = (int) $admin->empresa_id;
        app(TenantManager::class)->clear();
    }

    public function test_por_omissao_exige_autorizacao(): void
    {
        $this->assertFalse(TransferenciaInternacional::permitida());

        config(['atelier.apd_autorizacao_transferencia' => 'APD-123']);
        $this->assertTrue(TransferenciaInternacional::permitida());

        config(['atelier.apd_autorizacao_transferencia' => null, 'atelier.exigir_autorizacao_apd' => false]);
        $this->assertTrue(TransferenciaInternacional::permitida());
    }

    public function test_sem_autorizacao_os_canais_outbox_bloqueiam_sem_contactar_o_fornecedor(): void
    {
        Http::fake();

        foreach ([AtelierWhatsAppOutboxChannel::class, AtelierSmsOutboxChannel::class] as $canal) {
            $resultado = app($canal)->send($this->outbox());

            $this->assertFalse($resultado->accepted, $canal);
            $this->assertSame('apd_transfer_not_authorized', $resultado->errorCode, $canal);
            // Bloqueio: falha e alerta, nunca repetido automaticamente.
            $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify($resultado->errorCode));
        }

        Http::assertNothingSent();
    }

    public function test_com_autorizacao_o_canal_outbox_envia(): void
    {
        config(['atelier.apd_autorizacao_transferencia' => 'APD-TESTE-123']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $resultado = app(AtelierWhatsAppOutboxChannel::class)->send($this->outbox());

        $this->assertTrue($resultado->accepted);
        Http::assertSentCount(1);
    }

    public function test_com_a_exigencia_desligada_de_proposito_o_canal_envia(): void
    {
        config(['atelier.exigir_autorizacao_apd' => false]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->assertTrue(app(AtelierSmsOutboxChannel::class)->send($this->outbox())->accepted);
        Http::assertSentCount(1);
    }

    public function test_o_caminho_legado_tambem_recusa_sem_autorizacao(): void
    {
        Http::fake();

        foreach ([WhatsAppCanal::class, SmsCanal::class] as $canal) {
            try {
                app($canal)->enviar('244923456781', 'Olá');
                $this->fail('Era esperada uma exceção para '.$canal);
            } catch (NotificacaoException $e) {
                $this->assertStringContainsString('APD', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    private function outbox(): NotificationOutbox
    {
        return NotificationOutbox::withoutGlobalScopes()->create([
            'empresa_id' => $this->empresaId,
            'idempotency_key' => 'teste-'.Str::uuid(),
            'channel' => 'atelier.whatsapp',
            'recipient' => '244923456781',
            'payload' => ['message' => 'Mensagem de teste'],
            'status' => NotificationOutbox::STATUS_PENDING,
            'max_attempts' => 5,
            'available_at' => now(),
        ]);
    }
}
