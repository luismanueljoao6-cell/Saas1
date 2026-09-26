<?php

namespace Modules\Subscricoes\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Não usa BelongsToTenant: os planos são globais da plataforma, não
 * pertencem a nenhuma empresa em particular.
 */
class Plano extends Model
{
    use HasFactory;

    protected $fillable = [
        'nome',
        'slug',
        'descricao',
        'preco',
        'moeda',
        'periodo_dias',
        'limites',
        'ativo',
        'ordem',
    ];

    /**
     * Espelha os defaults definidos na migration. Sem isto, criar um Plano
     * sem indicar 'moeda' (ou os outros campos aqui listados) deixa a base
     * de dados aplicar o valor por omissão na tabela, mas o objeto Eloquent
     * em memória fica com esse atributo a null — e um null passado
     * explicitamente adiante (ex.: para Pagamento::create()) ignora
     * qualquer default de coluna lá também.
     */
    protected $attributes = [
        'moeda' => 'AOA',
        'periodo_dias' => 30,
        'ativo' => true,
        'ordem' => 0,
    ];

    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'limites' => 'array',
            'ativo' => 'boolean',
        ];
    }

    public function subscricoes(): HasMany
    {
        return $this->hasMany(Subscricao::class);
    }

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true)->orderBy('ordem');
    }
}
