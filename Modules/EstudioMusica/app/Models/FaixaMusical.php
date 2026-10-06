<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\BelongsToTenant;

class FaixaMusical extends Model
{
    use BelongsToTenant;

    protected $table = 'faixas_musicais';

    protected $fillable = [
        'empresa_id',
        'projeto_musical_id',
        'nome',
        'estado',
        'ordem',
    ];

    public function projetoMusical(): BelongsTo
    {
        return $this->belongsTo(ProjetoMusical::class);
    }

    public function versoes(): HasMany
    {
        return $this->hasMany(VersaoAudio::class)->latest('enviada_em');
    }

    public function ultimaVersao(): ?VersaoAudio
    {
        return $this->versoes()->first();
    }

    public function rotuloEstado(): string
    {
        return config('estudiomusica.estados_projeto.'.$this->estado, $this->estado);
    }
}
