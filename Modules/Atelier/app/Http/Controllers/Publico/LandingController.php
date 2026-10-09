<?php

namespace Modules\Atelier\Http\Controllers\Publico;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\SolicitarOrcamentoRequest;
use Modules\Atelier\Models\PedidoOrcamento;
use Modules\Atelier\Models\Perfil;
use Modules\Atelier\Models\PortfolioItem;
use Modules\Core\Models\Empresa;

/**
 * Requisito F. Nenhuma destas queries passa por TenantManager — Empresa,
 * Perfil e PortfolioItem não usam BelongsToTenant, exatamente para que uma
 * página pública os possa ler sem que exista um tenant "autenticado" no
 * pedido (ver as três classes para o porquê).
 */
class LandingController extends Controller
{
    public function mostrar(Empresa $empresa): View
    {
        $perfil = Perfil::where('empresa_id', $empresa->id)->first();

        abort_unless($perfil?->publicado, 404);

        return view('atelier::landing.mostrar', [
            'empresa' => $empresa,
            'perfil' => $perfil,
            'portfolioPorCategoria' => PortfolioItem::where('empresa_id', $empresa->id)
                ->orderBy('ordem')
                ->get()
                ->groupBy('categoria'),
            'categorias' => config('atelier.categorias_portfolio'),
        ]);
    }

    public function solicitarOrcamento(SolicitarOrcamentoRequest $request, Empresa $empresa): RedirectResponse
    {
        abort_unless(
            Perfil::where('empresa_id', $empresa->id)->where('publicado', true)->exists(),
            404
        );

        PedidoOrcamento::create([
            'empresa_id' => $empresa->id,
            'nome' => $request->validated('nome'),
            'contacto' => $request->validated('contacto'),
            'categoria_interesse' => $request->validated('categoria_interesse'),
            'mensagem' => $request->validated('mensagem'),
        ]);

        return back()->with('sucesso', 'Pedido enviado! Entraremos em contacto brevemente.');
    }
}
