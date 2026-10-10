<?php

namespace Modules\Atelier\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Atelier\Models\Pedido;

class PedidoMudouEstado
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Pedido $pedido,
        public string $estadoAnterior,
    ) {}
}
