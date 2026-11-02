<?php

namespace Modules\\Atelier\\Listeners;

use Illuminate\\Support\\Facades\\DB;
use Modules\\Atelier\\Models\\Campanha;
use Modules\\Atelier\\Models\\CampanhaEnvio;
use Modules\\Core\\Events\\NotificationStateChanged;
use Modules\\Core\\Models\\NotificationOutbox;

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

        DB::transaction(function () use ($event, $estado): void {
            // CampanhaEnvio não tem tenant scope próprio; a posse é
            // validada explicitamente através da campanha bloqueada abaixo.
            $envio = CampanhaEnvio::query()
                ->where('notification_outbox_id', $event->notificationId)
                ->lockForUpdate()
                ->first();

            if (! $envio) {
                return;
            }

            // O listener também corre em workers sem tenant activo. Retirar
            // a scope só é seguro aqui porque filtramos simultaneamente pelo
            // ID da campanha e pelo empresa_id vindo do evento da Outbox.
            $campanha = Campanha::withoutGlobalScopes()
                ->whereKey($envio->campanha_id)
                ->where('empresa_id', $event->empresaId)
                ->lockForUpdate()
                ->first();

            if (! $campanha) {
                return;
            }

            if ($envio->estado !== CampanhaEnvio::ESTADO_ENVIADO) {
                $envio->update([
                    'estado' => $estado,
                    'erro' => $estado === CampanhaEnvio::ESTADO_FALHOU
                        ? 'O transporte não aceitou a notificação após as tentativas disponíveis.'
                        : null,
                ]);
            }

            if ($campanha->estado !== 'em_processamento') {
                return;
            }

            $temPendente = $campanha->envios()
                ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
                ->exists();

            if ($temPendente) {
                return;
            }

            $temFalha = $campanha->envios()
                ->where('estado', CampanhaEnvio::ESTADO_FALHOU)
                ->exists();

            $campanha->update([
                'estado' => $temFalha ? 'concluida_com_falhas' : 'enviada',
                'enviada_em' => $campanha->enviada_em ?? now(),
            ]);
        }, 3);
    }
}
