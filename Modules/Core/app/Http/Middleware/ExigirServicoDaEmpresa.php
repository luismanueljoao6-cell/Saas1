<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;
use Symfony\Component\HttpFoundation\Response;

/**
 * Para rotas públicas (sem login) em que a empresa vem na própria URL:
 * ->middleware('servico.empresa:atelier'). Lê o parâmetro {empresa} já
 * resolvido pelo route-model binding e responde 404 se a empresa não
 * aderiu ao serviço, como se a página não existisse.
 *
 * Fail-closed: sem empresa resolvida, ou com um nome de serviço
 * desconhecido, nunca dá acesso.
 */
class ExigirServicoDaEmpresa
{
    public function handle(Request $request, Closure $next, string $servico): Response
    {
        $empresa = $request->route('empresa');
        $enum = Servico::tryFrom($servico);

        abort_unless(
            $empresa instanceof Empresa && $enum !== null && $empresa->temServico($enum),
            404
        );

        return $next($request);
    }
}
