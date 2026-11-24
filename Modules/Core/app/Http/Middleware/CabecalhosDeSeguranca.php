<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança básicos em todas as respostas do grupo 'web'.
 *
 * Nunca substitui um cabeçalho que a resposta já tenha (ex.: o convite define
 * Referrer-Policy: no-referrer). Desligável com CORE_CABECALHOS_SEGURANCA=false.
 *
 * Não define Content-Security-Policy: o layout usa um script inline (tema) e o
 * Alpine via CDN, por isso uma CSP útil exige nonce. Fica para uma fase própria.
 */
class CabecalhosDeSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        if (! config('core.seguranca.cabecalhos', true)) {
            return $resposta;
        }

        $cabecalhos = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];

        // HSTS só faz sentido (e só é aceite pelos navegadores) sobre HTTPS.
        if ($request->isSecure()) {
            $cabecalhos['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($cabecalhos as $nome => $valor) {
            if (! $resposta->headers->has($nome)) {
                $resposta->headers->set($nome, $valor);
            }
        }

        return $resposta;
    }
}
