<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\BelongsToTenant;

class VersaoAudio extends Model
{
    use BelongsToTenant;

    protected $table = 'versoes_audio';

    protected $fillable = [
        'empresa_id',
        'faixa_musical_id',
        'rotulo',
        'caminho_ficheiro',
        'estado',
        'enviada_em',
    ];

    protected function casts(): array
    {
        return [
            'enviada_em' => 'datetime',
        ];
    }

    public function faixaMusical(): BelongsTo
    {
        return $this->belongsTo(FaixaMusical::class);
    }

    public function marcadores(): HasMany
    {
        return $this->hasMany(MarcadorAudio::class)->orderBy('tempo_segundos');
    }

    public function aprovada(): bool
    {
        return $this->estado === 'aprovada';
    }
}
