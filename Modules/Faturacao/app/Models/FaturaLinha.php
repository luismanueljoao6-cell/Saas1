<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sem BelongsToTenant/empresa_id de propósito: uma linha só existe através
 * da Fatura a que pertence, e o isolamento por tenant já está garantido ao
 * nível da própria Fatura. Evita uma coluna redundante em cada tabela de
 * linhas do sistema.
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

    /**
     * Calcula e preenche valor_sem_iva/valor_iva/valor_total a partir de
     * quantidade, preco_unitario e taxa_iva. Chamado pelo FaturaService —
     * nunca confies em valores de totais vindos diretamente do pedido HTTP.
     */
    public function calcularValores(): static
    {
        $semIva = round((float) $this->quantidade * (float) $this->preco_unitario, 2);
        $iva = round($semIva * ((float) $this->taxa_iva / 100), 2);

        $this->valor_sem_iva = $semIva;
        $this->valor_iva = $iva;
        $this->valor_total = $semIva + $iva;

        return $this;
    }
}
