<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso nas rotas: ->middleware('papel:Administrador,Utilizador')
 * Tem de correr DEPOIS de 'tenant' (é o IdentificarTenant que define a
 * empresa em que os papéis do spatie/laravel-permission são avaliados).
 */
class ExigirPapel
{
    public function handle(Request $request, Closure $next, string ...$papeis): Response
    {
        $utilizador = $request->user();

        abort_unless(
            $utilizador && $utilizador->hasAnyRole($papeis),
            403,
            'Não tens permissão para aceder a esta área.'
        );

        return $next($request);
    }
}
