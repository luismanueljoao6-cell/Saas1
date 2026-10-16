<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\EstudioMusica\Models\TermosEstudio;
use Modules\EstudioMusica\Services\TermosEstudioService;

/**
 * Termos de Serviço do estúdio — só o Administrador (ver as rotas). Cada
 * publicação cria uma NOVA versão; as antigas ficam para provar o que cada
 * cliente aceitou e quando.
 */
class TermosEstudioController extends Controller
{
    public function __construct(protected TermosEstudioService $termos) {}

    public function index(Request $request): View
    {
        $empresa = Empresa::findOrFail($request->user()->empresa_id);

        return view('estudiomusica::termos.index', [
            'empresa' => $empresa,
            'atual' => $this->termos->atual($empresa->id, $empresa->nome_comercial),
            'historico' => TermosEstudio::withCount('aceites')->orderByDesc('versao')->get(),
        ]);
    }

    public function publicar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:150'],
            'conteudo' => ['required', 'string', 'min:100', 'max:30000'],
        ]);

        $termos = $this->termos->publicar($request->user()->empresa_id, $dados['titulo'], $dados['conteudo']);

        return back()->with('sucesso', "Versão {$termos->versao} publicada. Os clientes vão ter de a aceitar no portal.");
    }
}
