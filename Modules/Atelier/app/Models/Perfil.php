<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Empresa;

/**
 * Conteúdo da página pública/portfólio (requisito F). Sem BelongsToTenant
 * de propósito: é lido tanto pela área autenticada (editar) como pela
 * landing page pública (mostrar), e neste segundo caso não há nenhum
 * tenant identificado — a query já filtra explicitamente por empresa_id
 * vindo do slug, dispensando o global scope.
 */
class Perfil extends Model
{
    protected $table = 'atelier_perfis';

    protected $fillable = [
        'empresa_id',
        'historia',
        'especialidades',
        'equipa',
        'redes_sociais',
        'publicado',
    ];

    protected function casts(): array
    {
        return [
            'equipa' => 'array',
            'redes_sociais' => 'array',
            'publicado' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
