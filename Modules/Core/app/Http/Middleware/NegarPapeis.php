<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Complemento de «papel»: recusa quem tem algum dos papéis indicados, a não ser
 * que também seja Administrador. Serve para áreas que os papéis especializados
 * nunca devem alcançar por URL direto, mesmo com o serviço ativo na empresa
 * (ex.: uma Costureira só vê pedidos que lhe estão atribuídos; um Engenheiro de
 * estúdio não tem permissão de cobrança). Utilizadores sem papéis especializados
 * não são afetados.
 *
 * Tem de correr DEPOIS de 'tenant': os papéis do Spatie dependem da equipa.
 */
class NegarPapeis
{
    public function handle(Request $request, Closure $next, string ...$papeis): Response
    {
        $utilizador = $request->user();

        abort_if(
            $utilizador
                && ! $utilizador->hasRole('Administrador')
                && $utilizador->hasAnyRole($papeis),
            403,
            'O teu papel não dá acesso a esta área.'
        );

        return $next($request);
    }
}
