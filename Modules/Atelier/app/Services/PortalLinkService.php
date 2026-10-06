<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\URL;
use Modules\Faturacao\Models\Cliente;

/**
 * Gera o "link mágico" do Portal do Cliente (requisito E) como uma signed
 * URL do Laravel — sem tabela de tokens, sem sessão própria. A assinatura
 * cobre o cliente_id e a expiração; a rota valida com o middleware
 * 'signed' nativo. Isto é deliberadamente mais simples que uma sessão
 * autenticada tradicional — ver o aviso no README sobre as suas limitações
 * (um link partilhado dá acesso a quem o tiver, até expirar).
 */
class PortalLinkService
{
    public function gerarLinkAcesso(Cliente $cliente): string
    {
        return URL::temporarySignedRoute(
            'atelier.portal.mostrar',
            now()->addDays((int) config('atelier.portal_link_validade_dias')),
            ['cliente' => $cliente->id],
        );
    }

    /**
     * Um único nome de rota serve tanto o GET (mostrar formulário) como o
     * POST (submeter) — a validação de assinatura do Laravel não depende do
     * verbo HTTP, só do path+parâmetros+signature, por isso o mesmo link
     * funciona para os dois (ver routes/web.php, Route::match(['get','post'])).
     */
    public function gerarLinkReagendamento(Cliente $cliente, int $provaId): string
    {
        return URL::temporarySignedRoute(
            'atelier.portal.provas.reagendar',
            now()->addDays((int) config('atelier.portal_link_validade_dias')),
            ['cliente' => $cliente->id, 'prova' => $provaId],
        );
    }
}
