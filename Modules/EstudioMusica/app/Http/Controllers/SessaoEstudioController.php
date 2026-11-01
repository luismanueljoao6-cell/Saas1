<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Carbon\Carbon;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\EstudioMusica\Exceptions\ConflitoAgendamentoException;
use Modules\EstudioMusica\Http\Requests\GuardarSessaoEstudioRequest;
use Modules\EstudioMusica\Http\Requests\ReagendarSessaoEstudioRequest;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Services\AgendamentoService;
use Modules\EstudioMusica\Services\FaturacaoEstudioService;
use Modules\Faturacao\Models\Cliente;

class SessaoEstudioController extends Controller
{
    public function __construct(
        protected AgendamentoService $agendamento,
        protected FaturacaoEstudioService $faturacaoEstudio,
    ) {}

    public function index(Request $request): View
    {
        $projetoPreSelecionado = $request->filled('projeto')
            ? ProjetoMusical::query()->whereKey($request->integer('projeto'))->first()
            : null;

        return view('estudiomusica::sessoes.index', [
            'sessoes' => SessaoEstudio::with(['salaEstudio', 'cliente', 'engenheiro', 'projetoMusical'])
                ->orderByDesc('inicio_previsto')
                ->paginate(20),
            'salas' => SalaEstudio::ativas()->orderBy('nome')->get(),
            'clientes' => Cliente::orderBy('nome')->get(),
            // Sem a ligação sessão→projeto a cobrança por hora nunca chegava ao projeto (e este nunca
            // chegava a "quitado"): o formulário tem de a oferecer.
            'projetos' => ProjetoMusical::with('cliente')->where('estado', '!=', 'cancelado')->orderBy('nome')->get(),
            'membrosEquipa' => User::query()
                ->where('empresa_id', $request->user()->empresa_id)
                ->where('ativo', true)
                ->orderBy('name')
                ->get(),
            'projetoPreSelecionado' => $projetoPreSelecionado,
        ]);
    }

    public function store(GuardarSessaoEstudioRequest $request): RedirectResponse
    {
        try {
            $this->agendamento->criarSessao($request->validated());
        } catch (ConflitoAgendamentoException|InvalidArgumentException $e) {
            return back()->withInput()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Sessão agendada.');
    }

    /**
     * Recebe o id, não o model por binding implícito — ver o comentário
     * em SalaEstudioController::update() para o porquê (a posição de
     * SubstituteBindings face ao middleware 'tenant' na pipeline não está
     * verificada neste projeto; findOrFail() aqui dentro corre sempre
     * depois do middleware, sem essa ambiguidade).
     */
    public function checkIn(int $sessao): RedirectResponse
    {
        try {
            DB::transaction(fn () => $this->agendamento->registrarCheckIn(
                SessaoEstudio::query()->lockForUpdate()->findOrFail($sessao)
            ));
        } catch (InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Check-in registado.');
    }

    /**
     * Check-out fecha a sessão E, logo a seguir, gera a fatura por hora quando
     * aplicável (secção E) — "ao finalizar sessões" é literalmente o gatilho que o
     * requisito pede para a faturação automática.
     *
     * As duas operações são independentes de propósito: o fim real fica SEMPRE gravado.
     * Antes corriam na mesma transação, e uma falha da faturação (limite mensal de faturas
     * do plano, subscrição inativa, série por configurar) desfazia o check-out e devolvia
     * um erro 500 — o estúdio ficava com a sessão "em curso" e, ao repetir mais tarde, o
     * cliente pagava as horas entretanto passadas. Agora a sessão fica concluída e, se a
     * fatura não puder sair, o utilizador é avisado e usa «Faturar» quando o problema
     * estiver resolvido (cobrarSessao é idempotente).
     */
    public function checkOut(int $sessao): RedirectResponse
    {
        try {
            DB::transaction(function () use ($sessao) {
                // lockForUpdate: dois check-outs em simultâneo não passam ambos pela guarda de estado.
                $this->agendamento->registrarCheckOut(
                    SessaoEstudio::query()->lockForUpdate()->findOrFail($sessao)
                );
            });
        } catch (InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return $this->faturarSessao($sessao, 'Check-out registado.');
    }

    /** Emite (ou repete) a fatura por hora de uma sessão concluída que ainda não a tem. */
    public function faturar(int $sessao): RedirectResponse
    {
        $registo = SessaoEstudio::query()->findOrFail($sessao);

        if ($registo->estado !== 'concluida') {
            return back()->with('erro', 'Só se fatura uma sessão concluída (depois do check-out).');
        }

        if ($registo->fatura_id) {
            return back()->with('erro', 'Esta sessão já tem fatura.');
        }

        return $this->faturarSessao($sessao, null);
    }

    public function reagendar(ReagendarSessaoEstudioRequest $request, int $sessao): RedirectResponse
    {
        try {
            $this->agendamento->reagendar(
                SessaoEstudio::query()->findOrFail($sessao),
                Carbon::parse($request->validated('inicio_previsto')),
                Carbon::parse($request->validated('fim_previsto')),
            );
        } catch (ConflitoAgendamentoException|InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Sessão reagendada.');
    }

    public function cancelar(int $sessao): RedirectResponse
    {
        try {
            DB::transaction(fn () => $this->agendamento->cancelar(
                SessaoEstudio::query()->lockForUpdate()->findOrFail($sessao)
            ));
        } catch (InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Sessão cancelada.');
    }

    /**
     * Cobra a sessão em transação própria (com lock: dois pedidos não geram duas faturas por
     * hora). Qualquer recusa do motor de faturação — pedido inválido ou regra comercial como o
     * limite mensal do plano — vira um aviso, nunca um erro 500.
     */
    protected function faturarSessao(int $sessao, ?string $prefixo): RedirectResponse
    {
        try {
            $fatura = DB::transaction(fn () => $this->faturacaoEstudio->cobrarSessao(
                SessaoEstudio::query()->lockForUpdate()->findOrFail($sessao)
            ));
        } catch (InvalidArgumentException|DomainException $e) {
            return back()->with('erro', trim(
                ($prefixo ? $prefixo.' ' : '')
                ."A fatura NÃO foi emitida: {$e->getMessage()} Quando o problema estiver resolvido, usa «Faturar» nesta sessão."
            ));
        }

        if ($fatura) {
            return back()->with('sucesso', trim(($prefixo ? $prefixo.' ' : '')."Fatura {$fatura->numero_documento} emitida."));
        }

        return back()->with('sucesso', $prefixo ?? 'Esta sessão não tem nada a faturar (projeto por pacote, sala sem preço ou já faturada).');
    }
}
