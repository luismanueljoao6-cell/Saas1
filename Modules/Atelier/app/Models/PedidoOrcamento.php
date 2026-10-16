<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Traits\BelongsToTenant;

/**
 * Lead do formulário público de orçamento. Usa BelongsToTenant mesmo sendo
 * criado sem autenticação: quando não há tenant definido, a trait respeita
 * o empresa_id explícito (vindo do slug da landing page) em vez de o
 * ignorar — ver o comentário na migration e em BelongsToTenant.
 *
 * consentimento_em: momento em que o visitante aceitou o tratamento dos
 * dados para responder ao pedido.
 */
class PedidoOrcamento extends Model
{
    use BelongsToTenant;

    protected $table = 'atelier_pedidos_orcamento';

    protected $fillable = [
        'empresa_id',
        'nome',
        'contacto',
        'categoria_interesse',
        'mensagem',
        'estado',
        'consentimento_em',
    ];

    protected $attributes = [
        'estado' => 'novo',
    ];

    protected function casts(): array
    {
        return [
            'consentimento_em' => 'datetime',
        ];
    }
}
