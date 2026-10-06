<?php

namespace Modules\EstudioMusica\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica o Portal do Cliente por token temporário (secção C: "Login /
 * Token Temporário para Artistas/Produtores") — SEM passar por
 * Auth::user(): o Cliente nunca é um User do sistema, nunca faz login
 * como staff. Papel equivalente ao de Core\IdentificarTenant, mas na
 * ordem inversa: aqui primeiro descobrimos QUEM é (o token é único em
 * toda a plataforma, não só dentro de uma empresa) para só depois sabermos
 * a que empresa/tenant pertence.
 *
 * O cliente autenticado fica disponível aos controllers via
 * $request->attributes->get('cliente_portal') — não há guard/Auth
 * dedicado para isto, de propósito: seria complexidade a mais para um
 * único caso de uso de leitura/aprovação limitada.
 */
class VerificarTokenPortalCliente
{
    public function __construct(protected TenantManager $tenantManager)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token') ?? $request->query('token');

        if (! $token) {
            abort(404);
        }

        // Procura o cliente pelo token em bypass — antes disto não há
        // tenant nenhum identificado, e a TenantScope do Core bloquearia
        // (fail-closed) qualquer query normal a Cliente.
        $cliente = $this->tenantManager->semTenant(
            fn () => Cliente::query()
                ->where('token_portal', $token)
                ->where('token_portal_expira_em', '>', now())
                ->first()
        );

        if (! $cliente) {
            abort(403, 'Este link do Portal do Cliente é inválido ou já expirou. Pede à Receção um novo link.');
        }

        $this->tenantManager->set($cliente->empresa_id);
        $request->attributes->set('cliente_portal', $cliente);

        return $next($request);
    }
}
