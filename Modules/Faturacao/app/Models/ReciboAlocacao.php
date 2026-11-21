<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Traits\BelongsToTenant;

/**
 * Alocação de um recibo (AR/RG) a UMA linha de uma fatura de origem. Só de leitura e só de
 * acrescentar: nasce em ReciboAlocacaoService::registar() e nunca se altera nem se apaga (o
 * Eloquent recusa aqui e os triggers recusam na base de dados).
 *
 * Valores em cêntimos inteiros. `numero_linha_fiscal` é a posição (base 1) da linha na fatura de
 * origem; `sequencia` é a posição (base 1) da alocação dentro do recibo.
 */
class ReciboAlocacao extends Model
{
    use BelongsToTenant;

    protected $table = 'recibo_alocacoes';

    /** Vazio de propósito: não há criação por mass assignment, só pelo serviço. */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'recibo_id' => 'integer',
            'fatura_id' => 'integer',
            'fatura_linha_id' => 'integer',
            'numero_linha_fiscal' => 'integer',
            'sequencia' => 'integer',
            'liquido_centimos' => 'integer',
            'imposto_centimos' => 'integer',
            'bruto_centimos' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('As alocações de recibos são imutáveis.');
        });

        static::deleting(function (): void {
            throw new LogicException('As alocações de recibos são imutáveis.');
        });
    }

    public function recibo(): BelongsTo
    {
        return $this->belongsTo(Recibo::class);
    }

    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class);
    }

    public function linha(): BelongsTo
    {
        return $this->belongsTo(FaturaLinha::class, 'fatura_linha_id');
    }
}
