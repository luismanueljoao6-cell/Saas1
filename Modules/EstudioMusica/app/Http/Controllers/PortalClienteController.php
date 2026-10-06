<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\VersaoAudio;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal do Cliente (secção C). Todas as rotas passam por
 * VerificarTokenPortalCliente — o cliente autenticado está sempre em
 * $request->attributes->get('cliente_portal'), nunca em Auth::user().
 *
 * {versao} chega como int, nunca por binding implícito: o binding
 * implícito corre via SubstituteBindings, cuja posição face ao NOSSO
 * middleware 'portal.cliente' (que só define o tenant a meio da
 * pipeline) não está garantida — ver o mesmo cuidado, pela mesma razão,
 * em SalaEstudioController::update(). Resolver o model aqui dentro,
 * depois do middleware já ter corrido, elimina a ambiguidade.
 */
class PortalClienteController extends Controller
{
    public function mostrar(Request $request): View
    {
        $cliente = $request->attributes->get('cliente_portal');

        $projetos = ProjetoMusical::where('cliente_id', $cliente->id)
            ->with(['faixas.versoes.marcadores'])
            ->latest()
            ->get();

        return view('estudiomusica::portal.mostrar', [
            'cliente' => $cliente,
            'projetos' => $projetos,
        ]);
    }

    /**
     * Reprodução inline no player — livre para o dono do projeto, SEM
     * gate financeiro (o cliente tem de poder ouvir para poder aprovar
     * ou pedir ajustes antes de pagar o saldo final).
     *
     * response()->file() (BinaryFileResponse) em vez de Storage::response():
     * só o primeiro suporta pedidos HTTP Range, que o <audio> do browser
     * usa para saltar para um ponto da faixa (ex.: o marcador 01:23) sem
     * ter de descarregar o ficheiro inteiro antes.
     */
    public function reproduzir(Request $request, int $versao): BinaryFileResponse
    {
        $versao = $this->autorizarVersao($request, $versao);

        return response()->file(Storage::disk('local')->path($versao->caminho_ficheiro));
    }

    /**
     * "Download de arquivos liberado apenas após a quitação financeira do
     * saldo do projeto" (secção C) — o único ponto de todo o módulo onde
     * isto é verificado, de propósito (ver ProjetoMusical::financeiramenteQuitado()).
     */
    public function descarregar(Request $request, int $versao): StreamedResponse|RedirectResponse
    {
        $versao = $this->autorizarVersao($request, $versao);
        $projeto = $versao->faixaMusical->projetoMusical;

        if (! $projeto->financeiramenteQuitado()) {
            return back()->with('erro', 'O download fica disponível assim que o saldo do projeto estiver quitado.');
        }

        $extensao = pathinfo($versao->caminho_ficheiro, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($versao->caminho_ficheiro, "{$versao->rotulo}.{$extensao}");
    }

    public function comentar(Request $request, int $versao): RedirectResponse
    {
        $versao = $this->autorizarVersao($request, $versao);

        $dados = $request->validate([
            'tempo_segundos' => ['required', 'numeric', 'min:0'],
            'comentario' => ['required', 'string', 'max:2000'],
        ]);

        $versao->marcadores()->create([
            'tempo_segundos' => $dados['tempo_segundos'],
            'comentario' => $dados['comentario'],
            'criado_por_cliente' => true,
            'nome_autor' => $request->attributes->get('cliente_portal')->nome,
        ]);

        return back()->with('sucesso', 'Comentário adicionado.');
    }

    public function aprovar(Request $request, int $versao): RedirectResponse
    {
        $versao = $this->autorizarVersao($request, $versao);

        $versao->update(['estado' => 'aprovada']);

        return back()->with('sucesso', 'Versão aprovada!');
    }

    public function solicitarAjustes(Request $request, int $versao): RedirectResponse
    {
        $versao = $this->autorizarVersao($request, $versao);

        $versao->update(['estado' => 'ajustes_solicitados']);

        return back()->with('sucesso', 'Pedido de ajustes enviado à equipa.');
    }

    /**
     * Resolve a VersaoAudio pelo id e confirma que pertence a um projeto
     * do cliente autenticado no portal — devolve o model já carregado
     * para o chamador não ter de o ir buscar outra vez.
     */
    protected function autorizarVersao(Request $request, int $versaoId): VersaoAudio
    {
        $versao = VersaoAudio::with('faixaMusical.projetoMusical')->findOrFail($versaoId);
        $cliente = $request->attributes->get('cliente_portal');

        if ($versao->faixaMusical->projetoMusical->cliente_id !== $cliente->id) {
            abort(403);
        }

        return $versao;
    }
}
