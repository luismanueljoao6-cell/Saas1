<?php

namespace Modules\Subscricoes\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barreira de segurança antes de qualquer webhook de pagamento ser
 * processado. Delega a validação em si ao gateway concreto
 * (GatewayPagamentoInterface::validarPedidoWebhook), já que o mecanismo
 * exato (token partilhado, HMAC, IP allowlist...) varia por fornecedor.
 */
class VerificarAssinaturaWebhook
{
    public function __construct(protected GatewayPagamentoInterface $gateway) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->gateway->validarPedidoWebhook($request)) {
            Log::warning('Webhook de pagamento rejeitado: falha na validação de autenticidade.', [
                'gateway' => $this->gateway->identificador(),
                'ip' => $request->ip(),
            ]);

            abort(401, 'Assinatura de webhook inválida.');
        }

        return $next($request);
    }
}
