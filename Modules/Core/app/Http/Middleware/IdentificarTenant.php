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
    public function __construct(protected TenantManager $tenantManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Não confiar no estado deixado por outro pedido, sobretudo em
        // Octane e outros processos PHP persistentes.
        $this->tenantManager->clear();
        $registrar = class_exists(PermissionRegistrar::class)
            ? app(PermissionRegistrar::class)
            : null;

        if ($registrar !== null) {
            $registrar->setPermissionsTeamId(null);
        }

        try {
            $utilizador = Auth::user();

            if (! $utilizador) {
                // Não deveria acontecer se 'auth' correr antes, mas falha em
                // segurança de qualquer forma.
                throw new TenantNaoEncontradoException;
            }

            // Um utilizador desativado deixa de ter acesso IMEDIATAMENTE, mesmo
            // com a sessão já aberta (antes, `ativo` só era verificado no login).
            if (! $utilizador->ativo) {
                Log::info('Sessão terminada: utilizador desativado.', ['utilizador_id' => $utilizador->id]);

                // Pedidos de API (guard sanctum) não têm sessão nem logout().
                if ($request->expectsJson() || $request->is('api/*')) {
                    abort(403, 'Esta conta está desativada.');
                }

                Auth::logout();

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                return redirect()->route('core.login')->withErrors([
                    'email' => 'Esta conta foi desativada. Contacta o administrador da tua empresa.',
                ]);
            }

            // Super admins da plataforma não têm tenant; o contexto começa
            // limpo e só podem aceder a dados globais por bypass explícito.
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

            if ($registrar !== null) {
                $registrar->setPermissionsTeamId($utilizador->empresa_id);
            }

            return $next($request);
        } finally {
            // Limpa mesmo quando o controller lança exceção ou devolve cedo.
            $this->tenantManager->clear();

            if ($registrar !== null) {
                $registrar->setPermissionsTeamId(null);
            }
        }
    }
}
