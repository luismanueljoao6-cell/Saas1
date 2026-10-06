<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;

/**
 * IMPORTANTE — À SEMELHANÇA DA ProxyPayGateway (módulo Subscricoes): esta
 * classe foi escrita com base na documentação pública da WhatsApp Business
 * Cloud API, NÃO testada contra uma conta/número reais (sem esses, e sem
 * acesso à rede neste ambiente de desenvolvimento). Confirma antes de
 * produção:
 *   - o endpoint exato da versão da Graph API em uso;
 *   - se a mensagem exige um "template" pré-aprovado pela Meta fora da
 *     janela de 24h de conversa (mensagens de lembrete/notificação
 *     tipicamente exigem template, não texto livre);
 *   - o formato exato do número (com/sem "+", código do país).
 */
class WhatsAppCanal implements CanalNotificacaoInterface
{
    public function identificador(): string
    {
        return 'whatsapp';
    }

    public function enviar(string $destino, string $mensagem): void
    {
        $baseUrl = config('atelier.whatsapp.base_url');
        $token = config('atelier.whatsapp.token');
        $numeroId = config('atelier.whatsapp.numero_remetente_id');

        if (! $baseUrl || ! $token || ! $numeroId) {
            throw NotificacaoException::naoConfigurado('whatsapp');
        }

        // TODO: confirmar o formato exato do payload (texto livre vs.
        // template) contra a documentação atual antes de usar em produção.
        $resposta = Http::withToken($token)
            ->post("{$baseUrl}/{$numeroId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $destino,
                'type' => 'text',
                'text' => ['body' => $mensagem],
            ]);

        if ($resposta->failed()) {
            Log::error('Falha ao enviar WhatsApp', ['destino' => $destino, 'resposta' => $resposta->body()]);

            throw NotificacaoException::falhaNoEnvio('whatsapp', $resposta->body());
        }
    }
}
