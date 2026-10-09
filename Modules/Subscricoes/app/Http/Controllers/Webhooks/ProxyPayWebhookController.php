<?php

namespace Modules\Subscricoes\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Jobs\ProcessarPagamentoConfirmadoJob;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Throwable;

/**
 * A autenticidade do pedido já foi confirmada pelo middleware
 * VerificarAssinaturaWebhook antes de chegar aqui. Este controller só
 * interpreta o payload e despacha o Job — não faz nenhuma escrita na base
 * de dados diretamente, para responder ao gateway o mais depressa possível.
 */
class ProxyPayWebhookController extends Controller
{
    public function __construct(protected GatewayPagamentoInterface $gateway) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $notificacao = $this->gateway->interpretarNotificacao($request);
        } catch (Throwable $e) {
            Log::warning('Webhook de pagamento com payload não interpretável', [
                'gateway' => $this->gateway->identificador(),
                'erro' => $e->getMessage(),
            ]);

            // 200 propositado: alguns gateways reenviam agressivamente em
            // caso de erro. Já registámos o problema; um payload
            // indecifrável não vai ficar decifrável só por tentar de novo.
            return response()->json(['recebido' => true]);
        }

        ProcessarPagamentoConfirmadoJob::dispatch(
            $this->gateway->identificador(),
            $notificacao['referencia_externa'],
            $notificacao['payload'],
        );

        return response()->json(['recebido' => true]);
    }
}
