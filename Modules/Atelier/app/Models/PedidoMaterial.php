<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoMaterial extends Model
{
    protected $table = 'atelier_pedido_materiais';

    protected $fillable = ['pedido_id', 'descricao', 'quantidade', 'valor_unitario', 'valor_total', 'ordem'];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:2',
            'valor_unitario' => 'decimal:2',
            'valor_total' => 'decimal:2',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function calcularValores(): static
    {
        $this->valor_total = round((float) $this->quantidade * (float) $this->valor_unitario, 2);

        return $this;
    }
}
