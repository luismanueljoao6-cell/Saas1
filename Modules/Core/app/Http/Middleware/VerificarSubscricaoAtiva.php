<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Exceptions\SubscricaoInativaException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deve correr DEPOIS de IdentificarTenant. Bloqueia por completo o acesso
 * quando a empresa já não tem subscrição ativa NEM está dentro do período
 * de tolerância. A distinção mais fina — "dentro do grace period mas sem
 * poder emitir faturas" — não é feita aqui, porque o Core não tem faturas;
 * cabe ao Módulo de Faturação chamar Empresa::podeEmitirFaturas() no ponto
 * exato de criar uma fatura (ex.: numa Policy), para que a leitura de dados
 * antigos continue disponível durante o grace period.
 */
class VerificarSubscricaoAtiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = Auth::user();

        if (! $utilizador || $utilizador->is_super_admin) {
            return $next($request);
        }

        $empresa = $utilizador->empresa;

        if (! $empresa || ! $empresa->temAcesso()) {
            throw new SubscricaoInativaException;
        }

        return $next($request);
    }
}
