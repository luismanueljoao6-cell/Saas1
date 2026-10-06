<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\GuardarPortfolioItemRequest;
use Modules\Atelier\Models\PortfolioItem;

class PortfolioController extends Controller
{
    public function index(): View
    {
        $empresaId = request()->user()->empresa_id;

        return view('atelier::portfolio.index', [
            'itens' => PortfolioItem::where('empresa_id', $empresaId)->orderBy('categoria')->orderBy('ordem')->get(),
            'categorias' => config('atelier.categorias_portfolio'),
        ]);
    }

    public function guardar(GuardarPortfolioItemRequest $request): RedirectResponse
    {
        $caminho = $request->file('foto')->store('atelier/portfolio', 'public');

        PortfolioItem::create([
            'empresa_id' => $request->user()->empresa_id,
            'categoria' => $request->validated('categoria'),
            'titulo' => $request->validated('titulo'),
            'caminho_foto' => $caminho,
            'descricao' => $request->validated('descricao'),
            'destaque' => $request->boolean('destaque'),
        ]);

        return back()->with('sucesso', 'Item adicionado ao portfólio.');
    }

    /**
     * PortfolioItem não usa BelongsToTenant (é lido sem tenant pela landing
     * page pública — ver o model) — por isso a verificação de posse aqui
     * tem de ser explícita, não pode vir de um global scope.
     */
    public function destruir(PortfolioItem $portfolioItem): RedirectResponse
    {
        abort_unless($portfolioItem->empresa_id === request()->user()->empresa_id, 403);

        Storage::disk('public')->delete($portfolioItem->caminho_foto);
        $portfolioItem->delete();

        return back()->with('sucesso', 'Item removido do portfólio.');
    }
}
