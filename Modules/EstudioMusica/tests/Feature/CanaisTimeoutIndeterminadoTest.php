<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Exceptions\ResultadoIndeterminadoException;
use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Modules\EstudioMusica\Notifications\Channels\TwilioSmsChannel;
use Modules\EstudioMusica\Notifications\Channels\WhatsAppCloudApiChannel;
use Modules\EstudioMusica\Notifications\Outbox\EstudioWhatsAppOutboxChannel;
use Tests\TestCase;

/**
 * Um timeout depois de o pedido sair NUNCA pode virar retry nem fallback:
 * o fornecedor pode já ter enviado a mensagem (duplicado ao cliente).
 */
class CanaisTimeoutIndeterminadoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'estudiomusica.whatsapp_cloud_api.base_url' => 'https://graph.example.test/v1',
            'estudiomusica.whatsapp_cloud_api.phone_number_id' => '123',
            'estudiomusica.whatsapp_cloud_api.token_acesso' => 'token-teste',
            'estudiomusica.sms.account_sid' => 'ACteste',
            'estudiomusica.sms.auth_token' => 'token-teste',
            'estudiomusica.sms.numero_remetente' => '+15550000000',
            'estudiomusica.exigir_autorizacao_apd' => true,
            'estudiomusica.apd_autorizacao_transferencia' => 'APD-TESTE-123',
        ]);
    }

    public function test_timeout_nos_canais_reais_e_resultado_indeterminado(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));

        foreach ([new WhatsAppCloudApiChannel, new TwilioSmsChannel] as $canal) {
            try {
                $canal->enviar('923000000', 'Olá');
                $this->fail('Era esperada uma exceção em '.$canal->identificador());
            } catch (ResultadoIndeterminadoException $e) {
                $this->assertStringContainsString('tempo esgotado', $e->getMessage());
            }
        }
    }

    public function test_falha_antes_do_envio_continua_a_devolver_false(): void
    {
        foreach (['cURL error 7: Failed to connect', 'cURL error 6: Could not resolve host'] as $mensagem) {
            Http::fake(fn () => throw new ConnectionException($mensagem));

            $this->assertFalse((new WhatsAppCloudApiChannel)->enviar('923000000', 'Olá'), $mensagem);
            $this->assertFalse((new TwilioSmsChannel)->enviar('923000000', 'Olá'), $mensagem);
        }
    }

    public function test_resposta_http_de_erro_continua_a_devolver_false(): void
    {
        Http::fake(['*' => Http::response('erro interno', 500)]);

        $this->assertFalse((new WhatsAppCloudApiChannel)->enviar('923000000', 'Olá'));
        $this->assertFalse((new TwilioSmsChannel)->enviar('923000000', 'Olá'));
    }

    public function test_o_adaptador_outbox_deixa_passar_o_resultado_indeterminado(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $notificacao = (new NotificationOutbox)->forceFill([
            'recipient' => '923000000',
            'payload' => ['message' => 'Olá'],
        ]);

        $this->expectException(ResultadoIndeterminadoException::class);

        (new EstudioWhatsAppOutboxChannel(new WhatsAppCloudApiChannel))->send($notificacao);
    }

    public function test_o_job_nao_faz_fallback_para_sms_nem_repete_apos_resultado_indeterminado(): void
    {
        $whatsapp = new class implements CanalWhatsAppInterface
        {
            public int $tentativas = 0;

            public function identificador(): string
            {
                return 'whatsapp_cloud_api';
            }

            public function enviar(string $destino, string $mensagem): bool
            {
                $this->tentativas++;

                throw new ResultadoIndeterminadoException('timeout');
            }
        };
        $sms = $this->smsQueDevolve(true);

        // Não lança (não há repetição do job) e o SMS pago não é usado.
        (new EnviarMensagemClienteJob('923000000', null, 'Assunto', 'Mensagem'))->handle($whatsapp, $sms);

        $this->assertSame(1, $whatsapp->tentativas);
        $this->assertSame(0, $sms->tentativas);
    }

    public function test_o_fallback_para_sms_continua_quando_o_whatsapp_recusa_de_forma_definitiva(): void
    {
        $whatsapp = new class implements CanalWhatsAppInterface
        {
            public function identificador(): string
            {
                return 'whatsapp_cloud_api';
            }

            public function enviar(string $destino, string $mensagem): bool
            {
                return false;
            }
        };
        $sms = $this->smsQueDevolve(true);

        (new EnviarMensagemClienteJob('923000000', null, 'Assunto', 'Mensagem'))->handle($whatsapp, $sms);

        $this->assertSame(1, $sms->tentativas);
    }

    private function smsQueDevolve(bool $resultado): CanalSmsInterface
    {
        return new class($resultado) implements CanalSmsInterface
        {
            public int $tentativas = 0;

            public function __construct(private readonly bool $resultado) {}

            public function identificador(): string
            {
                return 'twilio';
            }

            public function enviar(string $destino, string $mensagem): bool
            {
                $this->tentativas++;

                return $this->resultado;
            }
        };
    }
}
