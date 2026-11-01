<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança básicos em todas as respostas (web, API, 401, 404...).
 * Registado como middleware GLOBAL (ver CoreServiceProvider).
 *
 * Nunca substitui um cabeçalho que a própria rota já tenha definido (o portal
 * do cliente, por exemplo, usa Referrer-Policy: no-referrer). Não define CSP
 * de propósito: as views usam scripts em linha e o Alpine.js vem de uma CDN.
 */
class CabecalhosDeSeguranca
{
    /** @var array<string, string> */
    protected const CABECALHOS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        foreach (self::CABECALHOS as $nome => $valor) {
            if (! $resposta->headers->has($nome)) {
                $resposta->headers->set($nome, $valor);
            }
        }

        // HSTS só em produção e só quando o pedido chegou por HTTPS (atrás de
        // um proxy, requer TRUSTED_PROXIES). Sem includeSubDomains de propósito.
        if ($request->isSecure() && app()->isProduction() && ! $resposta->headers->has('Strict-Transport-Security')) {
            $resposta->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $resposta;
    }
}
