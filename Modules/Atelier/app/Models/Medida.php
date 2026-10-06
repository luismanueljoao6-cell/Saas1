<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;

class Medida extends Model
{
    use BelongsToTenant;

    protected $table = 'atelier_medidas';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'busto',
        'cintura',
        'quadril',
        'ombro_a_ombro',
        'cavado',
        'coxa',
        'comprimento_manga',
        'contorno_braco',
        'comprimento_perna',
        'tornozelo',
        'altura',
        'observacoes',
        'registado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'busto' => 'decimal:2',
            'cintura' => 'decimal:2',
            'quadril' => 'decimal:2',
            'ombro_a_ombro' => 'decimal:2',
            'cavado' => 'decimal:2',
            'coxa' => 'decimal:2',
            'comprimento_manga' => 'decimal:2',
            'contorno_braco' => 'decimal:2',
            'comprimento_perna' => 'decimal:2',
            'tornozelo' => 'decimal:2',
            'altura' => 'decimal:2',
        ];
    }

    /** @return array<int, string> Nomes dos campos de medida, para iterar em views/relatórios sem repetir a lista à mão. */
    public static function camposMedida(): array
    {
        return [
            'busto', 'cintura', 'quadril', 'ombro_a_ombro', 'cavado', 'coxa',
            'comprimento_manga', 'contorno_braco', 'comprimento_perna', 'tornozelo', 'altura',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function registadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registado_por_id');
    }
}
