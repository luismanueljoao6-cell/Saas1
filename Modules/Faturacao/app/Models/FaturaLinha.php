<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Faturacao\Support\Dinheiro;

/**
 * Sem BelongsToTenant/empresa_id de propósito: uma linha só existe através
 * da Fatura a que pertence. A imutabilidade das linhas de faturas emitidas
 * é garantida por triggers de base de dados (migration de endurecimento).
 */
class FaturaLinha extends Model
{
    protected $fillable = [
        'fatura_id',
        'produto_id',
        'descricao',
        'quantidade',
        'preco_unitario',
        'taxa_iva',
        'valor_sem_iva',
        'valor_iva',
        'valor_total',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:3',
            'preco_unitario' => 'decimal:2',
            'taxa_iva' => 'decimal:2',
            'valor_sem_iva' => 'decimal:2',
            'valor_iva' => 'decimal:2',
            'valor_total' => 'decimal:2',
        ];
    }

    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }

    /** Nunca confies em totais vindos do pedido HTTP. */
    public function calcularValores(): static
    {
        $v = Dinheiro::calcularLinha($this->quantidade, $this->preco_unitario, $this->taxa_iva);

        $this->valor_sem_iva = $v['sem_iva'];
        $this->valor_iva = $v['iva'];
        $this->valor_total = $v['total'];

        return $this;
    }
}
