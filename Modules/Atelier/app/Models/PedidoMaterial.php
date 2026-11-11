<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Faturacao\Support\Dinheiro;

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
        // Quantidade × valor unitário em aritmética exata (sem float); o IVA só entra na fatura.
        $quantidade = (string) $this->quantidade;

        $this->valor_total = Dinheiro::milesimos($quantidade) > 0
            ? Dinheiro::calcularLinha($quantidade, (string) $this->valor_unitario, 0)['sem_iva']
            : '0.00';

        return $this;
    }
}
