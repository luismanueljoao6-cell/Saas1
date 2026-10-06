<?php

namespace Modules\EstudioMusica\Notifications\Channels;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Throwable;

/**
 * Integração com a API REST da Twilio para SMS. Tal como o
 * WhatsAppCloudApiChannel, o formato do endpoint foi reconstruído a partir
 * da documentação pública (https://www.twilio.com/docs/sms/api), não
 * testado com uma conta real — confirma antes de produção, incluindo se a
 * Twilio cobre entrega de SMS para números angolanos na tua conta.
 */
class TwilioSmsChannel implements CanalSmsInterface
{
    protected ?string $accountSid;

    protected ?string $authToken;

    protected ?string $numeroRemetente;

    public function __construct()
    {
        $this->accountSid = config('estudiomusica.sms.account_sid');
        $this->authToken = config('estudiomusica.sms.auth_token');
        $this->numeroRemetente = config('estudiomusica.sms.numero_remetente');
    }

    public function identificador(): string
    {
        return 'twilio';
    }

    public function enviar(string $destino, string $mensagem): bool
    {
        if (! $this->accountSid || ! $this->authToken || ! $this->numeroRemetente) {
            Log::warning('[EstudioMusica] Twilio SMS sem credenciais configuradas — mensagem não enviada.', [
                'destino' => $destino,
            ]);

            return false;
        }

        try {
            Http::asForm()
                ->withBasicAuth($this->accountSid, $this->authToken)
                ->timeout(10)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", [
                    'To' => $destino,
                    'From' => $this->numeroRemetente,
                    'Body' => $mensagem,
                ])
                ->throw();

            return true;
        } catch (RequestException $e) {
            Log::error('Twilio: falha HTTP ao enviar SMS', [
                'destino' => $destino,
                'status' => $e->response?->status(),
                'corpo' => $e->response?->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('Twilio: erro inesperado ao enviar SMS', [
                'destino' => $destino,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
