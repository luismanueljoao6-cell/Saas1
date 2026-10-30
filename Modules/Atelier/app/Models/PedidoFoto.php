<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fotos de referência de um pedido. São dados pessoais: ficam no disco
 * PRIVADO e só são servidas por rota autenticada (FotoController).
 * DISCO_LEGADO existe apenas para ficheiros antigos ainda não movidos.
 */
class PedidoFoto extends Model
{
    public const DISCO = 'local';

    public const DISCO_LEGADO = 'public';

    protected $table = 'atelier_pedido_fotos';

    protected $fillable = ['pedido_id', 'caminho', 'legenda'];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function url(): string
    {
        return route('atelier.pedidos.foto', $this->id);
    }
}
