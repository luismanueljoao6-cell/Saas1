<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\EstudioMusica\Exceptions\ConflitoAgendamentoException;
use Modules\EstudioMusica\Http\Requests\GuardarSessaoEstudioRequest;
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
    ) {
    }

    public function index(): View
    {
        return view('estudiomusica::sessoes.index', [
            'sessoes' => SessaoEstudio::with(['salaEstudio', 'cliente', 'engenheiro'])
                ->orderByDesc('inicio_previsto')
                ->paginate(20),
            'salas' => SalaEstudio::ativas()->orderBy('nome')->get(),
            'clientes' => Cliente::orderBy('nome')->get(),
        ]);
    }

    public function store(GuardarSessaoEstudioRequest $request): RedirectResponse
    {
        try {
            $this->agendamento->criarSessao($request->validated());
        } catch (ConflitoAgendamentoException $e) {
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
            $this->agendamento->registrarCheckIn(SessaoEstudio::findOrFail($sessao));
        } catch (InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Check-in registado.');
    }

    /**
     * Check-out fecha a sessão E, no mesmo momento, gera a fatura por
     * hora quando aplicável (secção E) — "ao finalizar sessões" é
     * literalmente o gatilho que o requisito pede para a faturação
     * automática. As duas operações correm na mesma transação: se a
     * faturação falhar a meio, o check-out também não fica gravado — não
     * queremos uma sessão "concluída" sem a fatura correspondente alguma
     * vez ter saído.
     */
    public function checkOut(int $sessao): RedirectResponse
    {
        try {
            $fatura = DB::transaction(function () use ($sessao) {
                $sessao = SessaoEstudio::findOrFail($sessao);
                $this->agendamento->registrarCheckOut($sessao);

                return $this->faturacaoEstudio->cobrarSessao($sessao);
            });
        } catch (InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        $mensagem = $fatura
            ? "Check-out registado. Fatura {$fatura->numero_documento} emitida."
            : 'Check-out registado.';

        return back()->with('sucesso', $mensagem);
    }

    public function cancelar(int $sessao): RedirectResponse
    {
        try {
            $this->agendamento->cancelar(SessaoEstudio::findOrFail($sessao));
        } catch (InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Sessão cancelada.');
    }
}
