<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoFoto extends Model
{
    protected $table = 'atelier_pedido_fotos';

    protected $fillable = ['pedido_id', 'caminho', 'legenda'];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function url(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->caminho);
    }
}
