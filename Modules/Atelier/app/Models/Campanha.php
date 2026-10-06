<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;

class Campanha extends Model
{
    use BelongsToTenant;

    protected $table = 'atelier_campanhas';

    protected $fillable = [
        'empresa_id',
        'nome',
        'canal',
        'segmento',
        'assunto',
        'mensagem',
        'agendada_para',
        'enviada_em',
        'estado',
        'criada_por_id',
    ];

    protected function casts(): array
    {
        return [
            'agendada_para' => 'datetime',
            'enviada_em' => 'datetime',
        ];
    }

    public function criadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por_id');
    }

    public function envios(): HasMany
    {
        return $this->hasMany(CampanhaEnvio::class);
    }

    public function segmentoRotulo(): string
    {
        return match ($this->segmento) {
            'todos' => 'Todos os clientes',
            'aniversariantes_mes' => 'Aniversariantes do mês',
            'inativos' => 'Clientes inativos',
            default => $this->segmento,
        };
    }
}
