<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Notifications\Outbox\AtelierSmsOutboxChannel;
use Modules\Atelier\Notifications\Outbox\AtelierWhatsAppOutboxChannel;
use Modules\Atelier\Services\Notificacoes\WhatsAppCanal;
use Modules\Atelier\Support\FalhaDeLigacao;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Exceptions\ResultadoIndeterminadoException;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;
use Tests\TestCase;
use Throwable;

/**
 * Um timeout depois de o pedido sair NUNCA pode virar retry automático:
 * o fornecedor pode já ter enviado a mensagem. Só as falhas que garantem
 * que o pedido nem saiu continuam a ser repetidas.
 */
class FalhaDeLigacaoIndeterminadaTest extends TestCase
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
            'atelier.apd_autorizacao_transferencia' => 'APD-TESTE',
        ]);

        $admin = app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Atelier ligacao', 'nif' => '500002001'],
            ['name' => 'Admin', 'email' => 'admin500002001@example.test', 'password' => 'Senha-segura-123'],
            ['atelier'],
        );
        $this->empresaId = (int) $admin->empresa_id;
        app(TenantManager::class)->clear();
    }

    public function test_timeout_depois_do_pedido_e_resultado_indeterminado_no_whatsapp_e_no_sms(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received'));

        foreach ([AtelierWhatsAppOutboxChannel::class, AtelierSmsOutboxChannel::class] as $canal) {
            $erro = $this->erroDe($canal);

            $this->assertInstanceOf(ResultadoIndeterminadoException::class, $erro, $canal);
        }
    }

    public function test_ligacao_cortada_sem_codigo_conhecido_tambem_e_indeterminada(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection reset by peer'));

        $this->assertInstanceOf(ResultadoIndeterminadoException::class, $this->erroDe(AtelierWhatsAppOutboxChannel::class));
    }

    public function test_falha_antes_do_envio_continua_a_ser_um_erro_comum_com_retry(): void
    {
        foreach (['cURL error 7: Failed to connect to graph.example.test', 'cURL error 6: Could not resolve host: sms.example.test'] as $mensagem) {
            Http::fake(fn () => throw new ConnectionException($mensagem));

            foreach ([AtelierWhatsAppOutboxChannel::class, AtelierSmsOutboxChannel::class] as $canal) {
                $erro = $this->erroDe($canal);

                $this->assertInstanceOf(NotificacaoException::class, $erro, $mensagem);
                $this->assertNotInstanceOf(ResultadoIndeterminadoException::class, $erro, $mensagem);
            }
        }
    }

    public function test_resposta_http_5xx_continua_a_ser_um_erro_comum(): void
    {
        Http::fake(['*' => Http::response('erro interno', 500)]);

        $erro = $this->erroDe(AtelierWhatsAppOutboxChannel::class);

        $this->assertInstanceOf(NotificacaoException::class, $erro);
        $this->assertNotInstanceOf(ResultadoIndeterminadoException::class, $erro);
    }

    public function test_o_canal_legado_continua_a_ver_uma_notificacao_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            app(WhatsAppCanal::class)->enviar('244923456781', 'Olá');
            $this->fail('Era esperada uma exceção.');
        } catch (NotificacaoException $e) {
            $this->assertStringContainsString('Resultado desconhecido', $e->getMessage());
        }
    }

    public function test_classificacao_das_falhas_de_ligacao(): void
    {
        foreach ([6, 7, 35, 60] as $codigo) {
            $this->assertTrue(FalhaDeLigacao::foiAntesDoEnvio(new ConnectionException("cURL error {$codigo}: x")), (string) $codigo);
        }

        foreach ([28, 52, 55, 56] as $codigo) {
            $this->assertFalse(FalhaDeLigacao::foiAntesDoEnvio(new ConnectionException("cURL error {$codigo}: x")), (string) $codigo);
        }

        $this->assertFalse(FalhaDeLigacao::foiAntesDoEnvio(new ConnectionException('sem código')));
    }

    private function erroDe(string $classeCanal): ?Throwable
    {
        $outbox = NotificationOutbox::withoutGlobalScopes()->create([
            'empresa_id' => $this->empresaId,
            'idempotency_key' => 'teste-'.Str::uuid(),
            'channel' => 'atelier.whatsapp',
            'recipient' => '244923456781',
            'payload' => ['message' => 'Mensagem de teste'],
            'status' => NotificationOutbox::STATUS_PENDING,
            'max_attempts' => 5,
            'available_at' => now(),
        ]);

        try {
            app($classeCanal)->send($outbox);
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }
}
