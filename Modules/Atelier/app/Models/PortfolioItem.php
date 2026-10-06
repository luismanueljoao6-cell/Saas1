<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Mesma decisão que Perfil: sem BelongsToTenant, porque a galeria é lida
 * sem tenant identificado a partir da landing page pública. A área
 * administrativa (Admin\PortfolioController) filtra por empresa_id
 * explicitamente a partir do utilizador autenticado.
 */
class PortfolioItem extends Model
{
    protected $table = 'atelier_portfolio_itens';

    protected $fillable = [
        'empresa_id',
        'categoria',
        'titulo',
        'caminho_foto',
        'descricao',
        'destaque',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'destaque' => 'boolean',
        ];
    }

    public function categoriaRotulo(): string
    {
        return config("atelier.categorias_portfolio.{$this->categoria}", $this->categoria);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->caminho_foto);
    }
}
