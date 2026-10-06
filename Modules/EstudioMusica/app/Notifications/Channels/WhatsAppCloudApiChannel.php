<?php

namespace Modules\EstudioMusica\Notifications\Channels;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Throwable;

/**
 * Integração com a WhatsApp Cloud API (Meta). Escolhida em vez do Twilio
 * WhatsApp por ser a via direta com o dono do produto (sem intermediário).
 *
 * IMPORTANTE: o formato do endpoint e do payload abaixo foi reconstruído a
 * partir da documentação pública da Meta for Developers, não de uma
 * chamada real testada com credenciais válidas (este ambiente não tem
 * acesso à rede). Confirma o formato atual em
 * https://developers.facebook.com/docs/whatsapp/cloud-api antes de ires
 * para produção — em particular, mensagens fora de uma janela de 24h de
 * conversação exigem um "message template" pré-aprovado pela Meta em vez
 * de texto livre; este canal assume texto livre (`type: text`), o que só
 * funciona dentro dessa janela.
 */
class WhatsAppCloudApiChannel implements CanalWhatsAppInterface
{
    protected string $baseUrl;

    protected ?string $phoneNumberId;

    protected ?string $tokenAcesso;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('estudiomusica.whatsapp_cloud_api.base_url'), '/');
        $this->phoneNumberId = config('estudiomusica.whatsapp_cloud_api.phone_number_id');
        $this->tokenAcesso = config('estudiomusica.whatsapp_cloud_api.token_acesso');
    }

    public function identificador(): string
    {
        return 'whatsapp_cloud_api';
    }

    public function enviar(string $destino, string $mensagem): bool
    {
        if (! $this->phoneNumberId || ! $this->tokenAcesso) {
            Log::warning('[EstudioMusica] WhatsApp Cloud API sem credenciais configuradas — mensagem não enviada.', [
                'destino' => $destino,
            ]);

            return false;
        }

        try {
            Http::withToken($this->tokenAcesso)
                ->acceptJson()
                ->timeout(10)
                ->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $this->normalizarNumero($destino),
                    'type' => 'text',
                    'text' => ['body' => $mensagem],
                ])
                ->throw();

            return true;
        } catch (RequestException $e) {
            Log::error('WhatsApp Cloud API: falha HTTP ao enviar mensagem', [
                'destino' => $destino,
                'status' => $e->response?->status(),
                'corpo' => $e->response?->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('WhatsApp Cloud API: erro inesperado ao enviar mensagem', [
                'destino' => $destino,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * A Cloud API espera o número em formato E.164 sem "+" (ex.:
     * 244923000000). TODO: confirmar se os números guardados em
     * clientes.telefone já vêm com o indicativo do país — este projeto é
     * pensado para Angola (+244), mas a validação de formato fica fora do
     * âmbito deste canal.
     */
    protected function normalizarNumero(string $numero): string
    {
        return ltrim(preg_replace('/\D/', '', $numero), '0');
    }
}
