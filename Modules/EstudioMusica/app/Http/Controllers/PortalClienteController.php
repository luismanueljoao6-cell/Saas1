<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;
use Modules\EstudioMusica\Services\PreviewAudioService;
use Modules\EstudioMusica\Services\TermosEstudioService;
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
 *
 * ATENÇÃO — os métodos das rotas com {token} E {versao} declaram `string $token`
 * mesmo sem o usarem: o Laravel entrega os parâmetros de rota por POSIÇÃO, não por
 * nome, e o token vem antes da versão. Sem ele, $versao recebia o token e o pedido
 * rebentava com TypeError (erro 500). Não o removas (PortalRotasHttpTest cobre isto).
 */
class PortalClienteController extends Controller
{
    public function __construct(
        protected PreviewAudioService $previews,
        protected TermosEstudioService $termos,
    ) {}

    public function mostrar(Request $request): View
    {
        $cliente = $request->attributes->get('cliente_portal');

        $projetos = ProjetoMusical::where('cliente_id', $cliente->id)
            ->with(['faixas.versoes.marcadores'])
            ->latest()
            ->get();

        $termos = $this->termos->atual($cliente->empresa_id);

        return view('estudiomusica::portal.mostrar', [
            'cliente' => $cliente,
            'projetos' => $projetos,
            'termos' => $termos,
            'termosAceites' => $this->termos->aceitou($cliente, $termos),
        ]);
    }

    /**
     * O cliente aceita a versão em vigor dos Termos de Serviço (data, IP em
     * hash e browser ficam registados). Sem isto não aprova nem descarrega.
     */
    public function aceitarTermos(Request $request): RedirectResponse
    {
        $cliente = $request->attributes->get('cliente_portal');

        $this->termos->registarAceite($cliente, $this->termos->atual($cliente->empresa_id), $request);

        return $this->paraOPortal($request)->with('sucesso', 'Termos de Serviço aceites. Obrigado!');
    }

    /** Mensagem de bloqueio se o cliente ainda não aceitou a versão em vigor dos termos. */
    protected function termosPendentes(Request $request): ?string
    {
        $cliente = $request->attributes->get('cliente_portal');

        return $this->termos->aceitou($cliente, $this->termos->atual($cliente->empresa_id))
            ? null
            : 'Antes de continuares, lê e aceita os Termos de Serviço no topo da página.';
    }

    /**
     * Reprodução inline no player — livre para o dono do projeto (o
     * cliente tem de poder ouvir para aprovar ou pedir ajustes antes de
     * pagar o saldo final), mas em PRÉ-VISUALIZAÇÃO até haver quitação.
     *
     * response()->file() (BinaryFileResponse) em vez de Storage::response():
     * só o primeiro suporta pedidos HTTP Range, que o <audio> do browser
     * usa para saltar para um ponto da faixa (ex.: o marcador 01:23) sem
     * ter de descarregar o ficheiro inteiro antes.
     */
    public function reproduzir(Request $request, string $token, int $versao): BinaryFileResponse
    {
        $versao = $this->autorizarVersao($request, $versao);
        $projeto = $versao->faixaMusical->projetoMusical;

        // Projeto quitado E termos aceites: o cliente já tem direito ao original. A regra é a
        // mesma do download — servir o original no player sem o aceite dos termos deixava esse
        // aceite contornável (bastava "guardar áudio como...").
        if ($projeto->financeiramenteQuitado() && $this->termosPendentes($request) === null) {
            return $this->ficheiroOuNaoEncontrado($versao->caminho_ficheiro);
        }

        // Ainda por pagar (ou termos por aceitar): só a pré-visualização (baixa qualidade).
        // Servir o original aqui deixava o "download só após quitação" contornável —
        // bastava guardar o áudio a partir do player.
        $preview = $this->previews->garantir($versao);

        if ($preview === null) {
            abort(503, 'A pré-visualização desta versão ainda não está disponível. Tenta novamente dentro de instantes ou contacta o estúdio.');
        }

        return response()->file(Storage::disk('local')->path($preview), ['Cache-Control' => 'private, no-store']);
    }

    /**
     * "Download de arquivos liberado apenas após a quitação financeira do
     * saldo do projeto" (secção C) — o único ponto de todo o módulo onde
     * isto é verificado, de propósito (ver ProjetoMusical::financeiramenteQuitado()).
     */
    public function descarregar(Request $request, string $token, int $versao): StreamedResponse|RedirectResponse
    {
        $versao = $this->autorizarVersao($request, $versao);
        $projeto = $versao->faixaMusical->projetoMusical;

        if (! $projeto->financeiramenteQuitado()) {
            return $this->paraOPortal($request)->with('erro', 'O download fica disponível assim que o saldo do projeto estiver quitado.');
        }

        // O original só sai depois de o cliente aceitar os Termos de Serviço
        // (propriedade dos masters, direitos de autor).
        if ($erro = $this->termosPendentes($request)) {
            return $this->paraOPortal($request)->with('erro', $erro);
        }

        abort_unless(
            Storage::disk('local')->exists($versao->caminho_ficheiro),
            404,
            'O ficheiro desta versão não está disponível. Contacta o estúdio.'
        );

        $extensao = pathinfo($versao->caminho_ficheiro, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($versao->caminho_ficheiro, "{$versao->rotulo}.{$extensao}");
    }

    public function comentar(Request $request, string $token, int $versao): RedirectResponse
    {
        $versao = $this->autorizarVersao($request, $versao);

        // Validação manual (e não $request->validate): a recusa tem de voltar ao PORTAL — o redirect
        // automático usa o "último GET" da sessão, que depois de tocar o áudio é o stream.
        $validador = Validator::make($request->all(), [
            'tempo_segundos' => ['required', 'numeric', 'min:0', 'max:86400'],
            'comentario' => ['required', 'string', 'max:2000'],
        ]);

        if ($validador->fails()) {
            return $this->paraOPortal($request)->withErrors($validador)->withInput();
        }

        $dados = $validador->validated();

        $versao->marcadores()->create([
            'tempo_segundos' => $dados['tempo_segundos'],
            'comentario' => $dados['comentario'],
            'criado_por_cliente' => true,
            'nome_autor' => $request->attributes->get('cliente_portal')->nome,
        ]);

        return $this->paraOPortal($request)->with('sucesso', 'Comentário adicionado.');
    }

    public function aprovar(Request $request, string $token, int $versao, NotificacaoEstudioService $notificacao): RedirectResponse
    {
        $versao = $this->autorizarVersao($request, $versao);

        if ($erro = $this->termosPendentes($request) ?? $this->registarDecisao($versao, 'aprovada')) {
            return $this->paraOPortal($request)->with('erro', $erro);
        }

        $notificacao->equipaDecisaoCliente($versao, 'aprovada');

        return $this->paraOPortal($request)->with('sucesso', 'Versão aprovada!');
    }

    public function solicitarAjustes(Request $request, string $token, int $versao, NotificacaoEstudioService $notificacao): RedirectResponse
    {
        $versao = $this->autorizarVersao($request, $versao);

        if ($erro = $this->registarDecisao($versao, 'ajustes_solicitados')) {
            return $this->paraOPortal($request)->with('erro', $erro);
        }

        $notificacao->equipaDecisaoCliente($versao, 'ajustes_solicitados');

        return $this->paraOPortal($request)->with('sucesso', 'Pedido de ajustes enviado à equipa.');
    }

    /**
     * Volta SEMPRE à página do portal, nunca com back(): o portal envia
     * Referrer-Policy: no-referrer, por isso o POST não leva Referer e o back() cai no
     * "último GET" guardado na sessão — que, depois de o cliente tocar o áudio, é o próprio
     * ficheiro de áudio (o cliente era largado no stream em vez de no portal).
     */
    protected function paraOPortal(Request $request): RedirectResponse
    {
        return redirect()->route('estudiomusica.portal.mostrar', ['token' => $request->route('token')]);
    }

    /** O original no disco, ou 404 limpo (response()->file() daria 500 se o ficheiro faltasse). */
    protected function ficheiroOuNaoEncontrado(string $caminho): BinaryFileResponse
    {
        $disco = Storage::disk('local');

        abort_unless($disco->exists($caminho), 404, 'O ficheiro desta versão não está disponível. Contacta o estúdio.');

        return response()->file($disco->path($caminho));
    }

    /**
     * Grava a decisão do cliente com lock na versão: dois cliques (ou dois pedidos em
     * simultâneo) já não decidem — nem notificam a equipa — duas vezes.
     *
     * @return string|null mensagem de recusa, ou null se a decisão foi gravada
     */
    protected function registarDecisao(VersaoAudio $versao, string $estado): ?string
    {
        return DB::transaction(function () use ($versao, $estado): ?string {
            VersaoAudio::query()->whereKey($versao->id)->lockForUpdate()->first();

            $atual = VersaoAudio::with('faixaMusical.projetoMusical')->findOrFail($versao->id);

            if ($erro = $this->motivoParaNaoDecidir($atual)) {
                return $erro;
            }

            $atual->update(['estado' => $estado]);

            return null;
        });
    }

    /**
     * Só se decide (aprova / pede ajustes) sobre a versão MAIS RECENTE da
     * faixa e enquanto ela ainda aguarda decisão: antes o cliente podia
     * aprovar uma versão antiga, ou trocar uma aprovação já dada por um
     * pedido de ajustes depois de o projeto estar concluído.
     */
    protected function motivoParaNaoDecidir(VersaoAudio $versao): ?string
    {
        if ($versao->faixaMusical->projetoMusical->estado === 'concluido') {
            return 'Este projeto já foi concluído.';
        }

        if ($versao->faixaMusical->ultimaVersao()?->id !== $versao->id) {
            return 'Existe uma versão mais recente desta faixa — decide sobre essa.';
        }

        if ($versao->estado !== 'pendente_aprovacao') {
            return 'Esta versão já tem uma decisão registada.';
        }

        return null;
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

        if ((int) $versao->faixaMusical->projetoMusical->cliente_id !== (int) $cliente->id) {
            abort(403);
        }

        return $versao;
    }
}
