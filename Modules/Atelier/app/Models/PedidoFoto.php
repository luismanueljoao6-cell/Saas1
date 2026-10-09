<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
        return Storage::disk('public')->url($this->caminho);
    }
}
