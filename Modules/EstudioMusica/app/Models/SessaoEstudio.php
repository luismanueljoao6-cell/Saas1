<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;

class SessaoEstudio extends Model
{
    use BelongsToTenant;

    protected $table = 'sessoes_estudio';

    protected $fillable = [
        'empresa_id',
        'sala_estudio_id',
        'cliente_id',
        'projeto_musical_id',
        'engenheiro_user_id',
        'tipo_servico',
        'inicio_previsto',
        'fim_previsto',
        'inicio_real',
        'fim_real',
        'estado',
        'lembrete_24h_enviado_em',
        'lembrete_2h_enviado_em',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'inicio_previsto' => 'datetime',
            'fim_previsto' => 'datetime',
            'inicio_real' => 'datetime',
            'fim_real' => 'datetime',
            'lembrete_24h_enviado_em' => 'datetime',
            'lembrete_2h_enviado_em' => 'datetime',
        ];
    }

    public function salaEstudio(): BelongsTo
    {
        return $this->belongsTo(SalaEstudio::class)->withTrashed();
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    /**
     * withTrashed: um projeto apagado não pode "desaparecer" daqui — a cobrança decide por ele
     * (um projeto por PACOTE apagado não pode passar a faturar as sessões à hora).
     */
    public function projetoMusical(): BelongsTo
    {
        return $this->belongsTo(ProjetoMusical::class)->withTrashed();
    }

    public function engenheiro(): BelongsTo
    {
        return $this->belongsTo(User::class, 'engenheiro_user_id');
    }

    public function rotuloTipoServico(): string
    {
        return config('estudiomusica.tipos_servico.'.$this->tipo_servico, $this->tipo_servico);
    }

    public function estaFeitoCheckIn(): bool
    {
        return $this->inicio_real !== null;
    }

    public function estaFeitoCheckOut(): bool
    {
        return $this->fim_real !== null;
    }

    /**
     * Duração real da sessão em horas, para efeitos de cobrança por hora
     * (ver FaturacaoEstudioService::cobrarSessao()). Cai para a duração
     * PREVISTA se a sessão ainda não teve check-out — nunca deixa uma
     * sessão concluída sem horas para cobrar só porque ninguém carregou
     * no botão de check-out.
     */
    public function rotuloEstado(): string
    {
        return [
            'agendada' => 'Agendada',
            'confirmada' => 'Confirmada',
            'em_curso' => 'Em curso',
            'concluida' => 'Concluída',
            'cancelada' => 'Cancelada',
        ][$this->estado] ?? (string) $this->estado;
    }

    public function duracaoMinutos(): int
    {
        $inicio = $this->inicio_real ?? $this->inicio_previsto;
        $fim = $this->fim_real ?? $this->fim_previsto;

        return (int) round(abs($inicio->diffInMinutes($fim)));
    }

    public function duracaoHoras(): float
    {
        return round($this->duracaoMinutos() / 60, 3);
    }
}
