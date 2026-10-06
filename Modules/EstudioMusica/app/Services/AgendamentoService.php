<?php

namespace Modules\EstudioMusica\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\EstudioMusica\Exceptions\ConflitoAgendamentoException;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SessaoEstudio;

/**
 * Concentra o agendamento de salas — em particular a prevenção de
 * conflitos (secção B: "Bloqueio de horários e prevenção de conflitos de
 * agenda"). Uma verificação de sobreposição sozinha não chega sob
 * concorrência: dois pedidos em simultâneo podem ambos passar a
 * verificação antes de qualquer um gravar. Por isso criarSessao() corre
 * dentro de uma transação com lockForUpdate sobre a própria sala —
 * qualquer outro pedido para a MESMA sala espera aqui até este
 * commit/rollback (salas diferentes continuam livres para agendar em
 * paralelo, sem se bloquearem uma à outra). Mesma técnica que
 * Faturacao\NumeracaoService usa para números de série sem lacunas.
 */
class AgendamentoService
{
    /**
     * @param  array<string, mixed>  $dados  Atributos de SessaoEstudio (ver $fillable no model)
     *
     * @throws ConflitoAgendamentoException
     */
    public function criarSessao(array $dados): SessaoEstudio
    {
        return DB::transaction(function () use ($dados) {
            $sala = SalaEstudio::query()->lockForUpdate()->findOrFail($dados['sala_estudio_id']);

            $inicio = Carbon::parse($dados['inicio_previsto']);
            $fim = Carbon::parse($dados['fim_previsto']);

            if ($this->verificarConflito($sala, $inicio, $fim)) {
                throw ConflitoAgendamentoException::paraSala($sala, $inicio->format('d/m H:i'), $fim->format('d/m H:i'));
            }

            return SessaoEstudio::create($dados);
        });
    }

    /**
     * @throws ConflitoAgendamentoException
     */
    public function reagendar(SessaoEstudio $sessao, Carbon $novoInicio, Carbon $novoFim): SessaoEstudio
    {
        return DB::transaction(function () use ($sessao, $novoInicio, $novoFim) {
            $sala = SalaEstudio::query()->lockForUpdate()->findOrFail($sessao->sala_estudio_id);

            if ($this->verificarConflito($sala, $novoInicio, $novoFim, ignorarSessaoId: $sessao->id)) {
                throw ConflitoAgendamentoException::paraSala($sala, $novoInicio->format('d/m H:i'), $novoFim->format('d/m H:i'));
            }

            $sessao->update([
                'inicio_previsto' => $novoInicio,
                'fim_previsto' => $novoFim,
                // Muda de horário: os lembretes já enviados deixam de
                // corresponder ao novo horário, por isso podem ser
                // reenviados pelo job.
                'lembrete_24h_enviado_em' => null,
                'lembrete_2h_enviado_em' => null,
            ]);

            return $sessao;
        });
    }

    /**
     * Sobreposição clássica de intervalos: [inicio, fim] colide com
     * [inicio_previsto, fim_previsto] sempre que inicio < fim_previsto E
     * fim > inicio_previsto. Sessões já canceladas nunca contam como
     * conflito — a sala fica livre outra vez assim que se cancela.
     */
    public function verificarConflito(SalaEstudio $sala, Carbon $inicio, Carbon $fim, ?int $ignorarSessaoId = null): bool
    {
        return SessaoEstudio::query()
            ->where('sala_estudio_id', $sala->id)
            ->where('estado', '!=', 'cancelada')
            ->when($ignorarSessaoId, fn ($query) => $query->where('id', '!=', $ignorarSessaoId))
            ->where('inicio_previsto', '<', $fim->toDateTimeString())
            ->where('fim_previsto', '>', $inicio->toDateTimeString())
            ->exists();
    }

    /**
     * Só uma sessão ainda por começar pode ter check-in.
     *
     * @throws InvalidArgumentException
     */
    public function registrarCheckIn(SessaoEstudio $sessao): SessaoEstudio
    {
        if (! in_array($sessao->estado, ['agendada', 'confirmada'], true)) {
            throw new InvalidArgumentException('Só é possível fazer check-in de uma sessão agendada ou confirmada.');
        }

        $sessao->update([
            'inicio_real' => now(),
            'estado' => 'em_curso',
        ]);

        return $sessao;
    }

    /**
     * Só uma sessão em curso (com check-in feito) pode ter check-out. Esta
     * guarda é o que impede um segundo clique em "check-out" de sobrescrever
     * a hora real de fim e, pior, de gerar uma segunda fatura por hora para
     * a mesma sessão (ver SessaoEstudioController::checkOut()).
     *
     * @throws InvalidArgumentException
     */
    public function registrarCheckOut(SessaoEstudio $sessao): SessaoEstudio
    {
        if ($sessao->estado !== 'em_curso') {
            throw new InvalidArgumentException('Só é possível fazer check-out de uma sessão em curso (com check-in feito).');
        }

        $sessao->update([
            'fim_real' => now(),
            'estado' => 'concluida',
        ]);

        return $sessao;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function cancelar(SessaoEstudio $sessao): SessaoEstudio
    {
        if (in_array($sessao->estado, ['concluida', 'cancelada'], true)) {
            throw new InvalidArgumentException('Uma sessão concluída ou já cancelada não pode ser cancelada.');
        }

        $sessao->update(['estado' => 'cancelada']);

        return $sessao;
    }
}
