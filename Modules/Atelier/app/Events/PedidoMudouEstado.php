<?php

namespace Modules\Atelier\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento assíncrono de mudança de estado. Transporta apenas identificadores:
 * modelos Eloquent serializados são restaurados sem global scopes pela fila,
 * pelo que não devem transportar relações de negócio entre tenants.
 */
class PedidoMudouEstado
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $pedidoId,
        public int $empresaId,
        public string $estadoAnterior,
    ) {}
}
