<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\NumeroTelefone;

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
 *
 * Os logs nunca incluem o número completo (dado pessoal) e o corpo da
 * resposta do fornecedor é truncado.
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

        try {
            // TODO: confirmar o formato exato do payload (texto livre vs.
            // template) contra a documentação atual antes de usar em produção.
            $resposta = Http::withToken($token)
                ->timeout((int) config('atelier.http_timeout_segundos', 15))
                ->post("{$baseUrl}/{$numeroId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $destino,
                    'type' => 'text',
                    'text' => ['body' => $mensagem],
                ]);
        } catch (ConnectionException $e) {
            Log::error('Falha de ligação ao enviar WhatsApp', ['destino' => NumeroTelefone::mascarar($destino)]);

            throw NotificacaoException::falhaNoEnvio('whatsapp', 'Sem ligação ao fornecedor (tempo esgotado ou rede indisponível).');
        }

        if ($resposta->failed()) {
            $corpo = Str::limit($resposta->body(), 300);

            Log::error('Falha ao enviar WhatsApp', ['destino' => NumeroTelefone::mascarar($destino), 'resposta' => $corpo]);

            throw NotificacaoException::falhaNoEnvio('whatsapp', $corpo);
        }
    }
}
