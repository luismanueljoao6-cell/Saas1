<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sem BelongsToTenant/empresa_id de propósito — só existe através da
 * VersaoAudio a que pertence (mesmo raciocínio de Faturacao\FaturaLinha).
 */
class MarcadorAudio extends Model
{
    protected $table = 'marcadores_audio';

    protected $fillable = [
        'versao_audio_id',
        'tempo_segundos',
        'comentario',
        'criado_por_cliente',
        'nome_autor',
    ];

    protected function casts(): array
    {
        return [
            'tempo_segundos' => 'decimal:2',
            'criado_por_cliente' => 'boolean',
        ];
    }

    public function versaoAudio(): BelongsTo
    {
        return $this->belongsTo(VersaoAudio::class);
    }

    /**
     * "01:23" a partir dos segundos guardados — usado pela UI do player.
     */
    public function tempoFormatado(): string
    {
        $segundos = (float) $this->tempo_segundos;

        return sprintf('%02d:%02d', floor($segundos / 60), floor($segundos % 60));
    }
}
