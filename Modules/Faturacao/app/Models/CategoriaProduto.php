<?php

namespace Modules\\Faturacao\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;
use Modules\\Core\\Traits\\BelongsToTenant;

class CategoriaProduto extends Model
{
    use BelongsToTenant;

    protected $table = 'categorias_produtos';

    protected $fillable = ['empresa_id', 'nome', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class, 'categoria_id');
    }
}
