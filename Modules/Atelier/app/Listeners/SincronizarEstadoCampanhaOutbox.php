<?php

namespace Modules\Atelier\Listeners;

use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Core\Events\NotificationStateChanged;
use Modules\Core\Models\NotificationOutbox;

class SincronizarEstadoCampanhaOutbox
{
    public function handle(NotificationStateChanged $event): void
    {
        $estado = match ($event->status) {
            NotificationOutbox::STATUS_ACCEPTED => CampanhaEnvio::ESTADO_ACEITE,
            NotificationOutbox::STATUS_FAILED,
            NotificationOutbox::STATUS_DEAD_LETTER => CampanhaEnvio::ESTADO_FALHOU,
            default => null,
        };

        if ($estado === null) {
            return;
        }

        CampanhaEnvio::query()
            ->where('notification_outbox_id', $event->notificationId)
            ->whereHas('campanha', fn ($query) => $query->where('empresa_id', $event->empresaId))
            ->whereNotIn('estado', [CampanhaEnvio::ESTADO_ENVIADO])
            ->update([
                'estado' => $estado,
                'erro' => $estado === CampanhaEnvio::ESTADO_FALHOU
                    ? 'O transporte não aceitou a notificação após as tentativas disponíveis.'
                    : null,
                'updated_at' => now(),
            ]);
    }
}
