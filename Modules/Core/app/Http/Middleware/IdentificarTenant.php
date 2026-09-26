<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Core\Exceptions\TenantNaoEncontradoException;
use Modules\Core\Services\TenantManager;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deve correr DEPOIS do middleware 'auth' em qualquer rota autenticada de
 * negócio. Define o tenant atual a partir da empresa do utilizador — nunca
 * a partir de input do próprio pedido (query string, header, etc.), para
 * impedir que um utilizador autenticado se faça passar por outra empresa.
 */
class IdentificarTenant
{
    public function __construct(protected TenantManager $tenantManager)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = Auth::user();

        if (! $utilizador) {
            // Não deveria acontecer se 'auth' correr antes, mas falha em
            // segurança de qualquer forma.
            throw new TenantNaoEncontradoException;
        }

        // Super admins da plataforma não têm empresa_id — navegam sem
        // tenant definido (a TenantScope, por omissão, bloqueia tudo; usa
        // TenantManager::semTenant() explicitamente nas rotas de admin).
        if ($utilizador->is_super_admin) {
            return $next($request);
        }

        if (! $utilizador->empresa_id) {
            Log::warning('Utilizador autenticado sem empresa associada.', [
                'utilizador_id' => $utilizador->id,
            ]);

            throw new TenantNaoEncontradoException;
        }

        $this->tenantManager->set($utilizador->empresa_id);

        // Faz o spatie/laravel-permission usar a mesma empresa como "team",
        // para que papéis/permissões fiquem corretamente isolados por tenant.
        if (class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($utilizador->empresa_id);
        }

        return $next($request);
    }
}
