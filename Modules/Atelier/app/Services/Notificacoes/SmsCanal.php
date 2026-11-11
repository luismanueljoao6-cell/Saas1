<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\FalhaDeLigacao;
use Modules\Atelier\Support\NumeroTelefone;
use Modules\Atelier\Support\TransferenciaInternacional;

/**
 * Genérico de propósito: o requisito não especifica qual gateway de SMS
 * angolano usar, ao contrário do pagamento (onde o README do Subscricoes
 * já tinha escolhido ProxyPay/Multicaixa). Assume um endpoint REST simples
 * (destino + mensagem + api_key) — troca o corpo do pedido pelo formato
 * exato do fornecedor escolhido antes de usar. Ver aviso equivalente em
 * WhatsAppCanal (incluindo o cuidado de não registar números completos).
 */
class SmsCanal implements CanalNotificacaoInterface
{
    public function identificador(): string
    {
        return 'sms';
    }

    public function enviar(string $destino, string $mensagem): void
    {
        if (! TransferenciaInternacional::permitida()) {
            throw NotificacaoException::transferenciaNaoAutorizada('sms');
        }

        $baseUrl = config('atelier.sms.base_url');
        $apiKey = config('atelier.sms.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw NotificacaoException::naoConfigurado('sms');
        }

        try {
            $resposta = Http::withToken($apiKey)
                ->timeout((int) config('atelier.http_timeout_segundos', 15))
                ->post($baseUrl, [
                    'remetente' => config('atelier.sms.remetente'),
                    'destino' => $destino,
                    'mensagem' => $mensagem,
                ]);
        } catch (ConnectionException $e) {
            Log::error('Falha de ligação ao enviar SMS', ['destino' => NumeroTelefone::mascarar($destino)]);

            if (FalhaDeLigacao::foiAntesDoEnvio($e)) {
                throw NotificacaoException::falhaNoEnvio('sms', 'Sem ligação ao fornecedor (rede indisponível).');
            }

            // Tempo esgotado ou ligação cortada: o fornecedor pode já ter recebido a mensagem.
            throw NotificacaoException::resultadoIndeterminado('sms', 'ligação interrompida ou tempo esgotado depois do pedido.');
        }

        if ($resposta->failed()) {
            $corpo = Str::limit($resposta->body(), 300);

            Log::error('Falha ao enviar SMS', ['destino' => NumeroTelefone::mascarar($destino), 'resposta' => $corpo]);

            throw NotificacaoException::falhaNoEnvio('sms', $corpo);
        }
    }
}
