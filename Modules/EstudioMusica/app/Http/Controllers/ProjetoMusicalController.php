<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\EstudioMusica\Http\Requests\GuardarProjetoMusicalRequest;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Services\FaturacaoEstudioService;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;
use Modules\EstudioMusica\Services\PerfilArtistaService;
use Modules\Faturacao\Models\Cliente;

/**
 * Nota sobre os parâmetros de rota: recebem sempre o id (int), nunca o
 * model por binding implícito — ver o comentário em
 * SalaEstudioController::update() para o porquê. findOrFail() dentro do
 * método corre sempre depois de 'tenant' já ter identificado a empresa.
 */
class ProjetoMusicalController extends Controller
{
    public function __construct(protected FaturacaoEstudioService $faturacaoEstudio) {}

    public function index(): View
    {
        return view('estudiomusica::projetos.index', [
            'projetos' => ProjetoMusical::with('cliente')->latest()->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('estudiomusica::projetos.criar', [
            'clientes' => Cliente::orderBy('nome')->get(),
            'membrosEquipa' => User::orderBy('name')->get(),
        ]);
    }

    public function store(GuardarProjetoMusicalRequest $request): RedirectResponse
    {
        $projeto = ProjetoMusical::create($request->validated());

        return redirect()->route('estudiomusica.projetos.show', $projeto)->with('sucesso', 'Projeto criado.');
    }

    public function show(int $projeto): View
    {
        $projeto = ProjetoMusical::with([
            'faixas.versoes.marcadores', 'sessoes.salaEstudio', 'sessoes.engenheiro', 'cliente', 'faturas', 'recibos',
        ])->findOrFail($projeto);

        return view('estudiomusica::projetos.show', [
            'projeto' => $projeto,
            'estadosDisponiveis' => config('estudiomusica.estados_projeto'),
        ]);
    }

    public function atualizarEstado(Request $request, int $projeto, NotificacaoEstudioService $notificacao): RedirectResponse
    {
        $request->validate([
            'estado' => ['required', Rule::in(array_keys(config('estudiomusica.estados_projeto')))],
        ]);

        $projeto = ProjetoMusical::findOrFail($projeto);
        $novoEstado = $request->input('estado');

        if ($novoEstado === 'concluido' && ! $projeto->todasAsFaixasAprovadas()) {
            return back()->with('erro', 'Ainda há faixas sem versão aprovada pelo cliente — o projeto não pode ser marcado como concluído.');
        }

        $projeto->atualizarEstado($novoEstado);

        if ($projeto->estado === 'concluido') {
            $notificacao->projetoConcluido($projeto);
        } else {
            $notificacao->atualizacaoEstadoProjeto($projeto);
        }

        return back()->with('sucesso', 'Estado do projeto atualizado.');
    }

    public function gerarSinal(int $projeto): RedirectResponse
    {
        $projeto = ProjetoMusical::findOrFail($projeto);

        try {
            $this->faturacaoEstudio->gerarFaturaSinal($projeto);
        } catch (InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Fatura de sinal emitida.');
    }

    public function gerarSaldoFinal(int $projeto): RedirectResponse
    {
        $projeto = ProjetoMusical::findOrFail($projeto);

        try {
            $this->faturacaoEstudio->gerarFaturaSaldoFinal($projeto);
        } catch (InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Fatura de saldo final emitida.');
    }

    public function gerarLinkPortal(int $projeto, PerfilArtistaService $perfilArtista, NotificacaoEstudioService $notificacao): RedirectResponse
    {
        $projeto = ProjetoMusical::findOrFail($projeto);
        $token = $perfilArtista->gerarTokenPortal($projeto->cliente);
        $url = route('estudiomusica.portal.mostrar', ['token' => $token]);

        $notificacao->linkPortal($projeto->cliente, $url);

        return back()->with('sucesso', "Link do portal enviado ao cliente. Link: {$url}");
    }
}
