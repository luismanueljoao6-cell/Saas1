<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;

class Prova extends Model
{
    use BelongsToTenant;

    protected $table = 'atelier_provas';

    protected $fillable = [
        'empresa_id',
        'pedido_id',
        'tipo',
        'data_hora_agendada',
        'data_hora_proposta_cliente',
        'estado',
        'lembrete_enviado_em',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'data_hora_agendada' => 'datetime',
            'data_hora_proposta_cliente' => 'datetime',
            'lembrete_enviado_em' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function tipoRotulo(): string
    {
        return match ($this->tipo) {
            'primeira' => '1ª Prova',
            'segunda' => '2ª Prova',
            'entrega' => 'Entrega',
            default => $this->tipo,
        };
    }

    public function precisaDeLembrete(): bool
    {
        if ($this->lembrete_enviado_em !== null || $this->estado === 'concluida') {
            return false;
        }

        $horasAntes = (int) config('atelier.lembrete_prova_horas_antes');

        return $this->data_hora_agendada->isFuture()
            && now()->addHours($horasAntes)->greaterThanOrEqualTo($this->data_hora_agendada);
    }
}
