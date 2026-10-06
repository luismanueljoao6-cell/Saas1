<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;

/**
 * Genérico de propósito: o requisito não especifica qual gateway de SMS
 * angolano usar, ao contrário do pagamento (onde o README do Subscricoes
 * já tinha escolhido ProxyPay/Multicaixa). Assume um endpoint REST simples
 * (destino + mensagem + api_key) — troca o corpo do pedido pelo formato
 * exato do fornecedor escolhido antes de usar. Ver aviso equivalente em
 * WhatsAppCanal.
 */
class SmsCanal implements CanalNotificacaoInterface
{
    public function identificador(): string
    {
        return 'sms';
    }

    public function enviar(string $destino, string $mensagem): void
    {
        $baseUrl = config('atelier.sms.base_url');
        $apiKey = config('atelier.sms.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw NotificacaoException::naoConfigurado('sms');
        }

        $resposta = Http::withToken($apiKey)
            ->post($baseUrl, [
                'remetente' => config('atelier.sms.remetente'),
                'destino' => $destino,
                'mensagem' => $mensagem,
            ]);

        if ($resposta->failed()) {
            Log::error('Falha ao enviar SMS', ['destino' => $destino, 'resposta' => $resposta->body()]);

            throw NotificacaoException::falhaNoEnvio('sms', $resposta->body());
        }
    }
}
