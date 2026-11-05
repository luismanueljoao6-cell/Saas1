<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;

/**
 * Um pedido manual de reenvio de campanha ("Reenviar falhados").
 *
 * É criado de forma atómica no momento em que o pedido é aceite (ver
 * CampanhaController::enviarAgora) e é a ÚNICA autorização que um job tem
 * para reenviar uma campanha já concluída: o job leva o token e recusa-se
 * a correr se o pedido não existir, já tiver fechado ou tiver expirado.
 * Fica guardado como histórico das tentativas manuais.
 */
class CampanhaReenvio extends Model
{
    use BelongsToTenant;

    public const ACEITE = 'aceite';

    public const INICIADO = 'iniciado';

    public const CONCLUIDO = 'concluido';

    public const IGNORADO = 'ignorado';

    public const FALHADO = 'falhado';

    public const EXPIRADO = 'expirado';

    /** Um pedido aberto há mais tempo do que isto é considerado abandonado (job perdido ou worker morto). */
    public const VALIDADE_MINUTOS = 60;

    protected $table = 'atelier_campanha_reenvios';

    protected $fillable = [
        'empresa_id',
        'campanha_id',
        'token',
        'estado',
        'aberto',
        'solicitado_por_id',
        'solicitado_em',
        'iniciado_em',
        'concluido_em',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'aberto' => 'boolean',
            'solicitado_em' => 'datetime',
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
    }

    public function campanha(): BelongsTo
    {
        return $this->belongsTo(Campanha::class);
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por_id');
    }

    /**
     * Fecha o pedido de forma condicional (só se ainda estiver aberto), por
     * isso é seguro chamar mais do que uma vez e nunca reabre nem sobrepõe
     * um estado final já registado.
     */
    public function fechar(string $estado, ?string $motivo = null): bool
    {
        return $this->newQuery()
            ->withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->where('aberto', true)
            ->update([
                'estado' => $estado,
                'aberto' => null,
                'concluido_em' => now(),
                'motivo' => $motivo !== null ? mb_substr($motivo, 0, 500) : null,
                'updated_at' => now(),
            ]) === 1;
    }

    /** Fecha como expirados os pedidos abertos e abandonados de uma campanha. */
    public static function expirarAbandonados(int $campanhaId): int
    {
        $limite = now()->subMinutes(self::VALIDADE_MINUTOS);

        return static::query()
            ->where('campanha_id', $campanhaId)
            ->where('aberto', true)
            ->where(fn ($q) => $q
                ->where('iniciado_em', '<', $limite)
                ->orWhere(fn ($q) => $q->whereNull('iniciado_em')->where('solicitado_em', '<', $limite)))
            ->update([
                'estado' => self::EXPIRADO,
                'aberto' => null,
                'concluido_em' => now(),
                'motivo' => 'Pedido abandonado: o job não terminou dentro do prazo.',
                'updated_at' => now(),
            ]);
    }
}
