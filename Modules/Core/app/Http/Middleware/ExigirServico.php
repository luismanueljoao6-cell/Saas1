<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Support\Servico;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('servico:atelier'). Deve correr DEPOIS de 'auth' e
 * 'tenant'. Esconder o menu não protege nada — é este middleware que
 * impede o acesso direto por URL a um serviço a que a empresa não aderiu.
 */
class ExigirServico
{
    public function handle(Request $request, Closure $next, string $servico): Response
    {
        $utilizador = Auth::user();

        if (! $utilizador || $utilizador->is_super_admin) {
            return $next($request);
        }

        $enum = Servico::tryFrom($servico);

        // Fail-closed: um nome de serviço desconhecido nunca dá acesso.
        if ($enum === null || ! $utilizador->empresa?->temServico($enum)) {
            if ($request->expectsJson()) {
                abort(403, 'A tua empresa não aderiu a este serviço.');
            }

            return redirect()
                ->route('core.painel')
                ->with('erro', 'A tua empresa não aderiu ao serviço "'.($enum?->rotulo() ?? $servico).'".');
        }

        return $next($request);
    }
}
