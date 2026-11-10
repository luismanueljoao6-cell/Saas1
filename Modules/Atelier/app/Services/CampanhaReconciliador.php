<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Core\Models\NotificationOutbox;
use Throwable;

/**
 * Alinha os envios e o estado de uma campanha com o que a Outbox realmente fez.
 *
 * A listener SincronizarEstadoCampanhaOutbox faz isto em tempo real, mas um
 * evento pode perder-se (worker morto a meio, falha da listener, ...) e uma
 * campanha "em processamento" não tem outra forma de sair desse estado: o
 * botão de envio recusa-a. Este serviço é a rede de segurança — idempotente,
 * lê sempre o estado REAL da Outbox (limitado à empresa da campanha) e nunca
 * envia nada: só corrige o registo.
 *
 * Ordem de locks: a listener bloqueia primeiro o envio e depois a campanha.
 * Aqui os envios são atualizados SEM lock de campanha (updates condicionais e
 * atómicos) e a campanha só é bloqueada no fim, a ler — nunca se pede um lock
 * de envio com o da campanha tomado, por isso não há deadlock com a listener.
 */
class CampanhaReconciliador
{
    /** Um envio 'pendente' sem Outbox mais antigo do que isto foi abandonado (o job tem timeout de 15 min). */
    public const ENVIO_ORFAO_APOS_MINUTOS = 30;

    /** A Outbox aceitou a notificação (ou já foi mais longe). */
    private const OUTBOX_ACEITE = [
        NotificationOutbox::STATUS_ACCEPTED,
        NotificationOutbox::STATUS_DELIVERED,
        NotificationOutbox::STATUS_READ,
    ];

    /** A Outbox terminou sem sucesso: a notificação nunca vai ser entregue. */
    private const OUTBOX_FALHADA = [
        NotificationOutbox::STATUS_FAILED,
        NotificationOutbox::STATUS_DEAD_LETTER,
        NotificationOutbox::STATUS_CANCELLED,
    ];

    /**
     * @param  bool  $abandonarOrfaos  Também marca como falhados os envios 'pendentes' que nunca
     *                                 chegaram à Outbox e estão parados há demasiado tempo. Só deve
     *                                 ser pedido por quem garante que nenhum job está a enviar.
     */
    public function reconciliar(Campanha $campanha, bool $abandonarOrfaos = false): void
    {
        $this->alinharComAOutbox($campanha);

        if ($abandonarOrfaos) {
            $this->abandonarEnviosOrfaos($campanha);
        }

        $this->fecharSeTerminou($campanha);

        $campanha->refresh();
    }

    /**
     * Reconcilia as campanhas paradas em 'em_processamento' (comando agendado).
     *
     * @return int quantas campanhas saíram de 'em_processamento'
     */
    public function reconciliarEmProcessamento(int $limite = 200): int
    {
        $fechadas = 0;

        $campanhas = Campanha::withoutGlobalScopes()
            ->where('estado', 'em_processamento')
            ->orderBy('id')
            ->limit($limite)
            ->get();

        foreach ($campanhas as $campanha) {
            // Mesmo lock do DispararCampanhaJob: nunca reconciliar a meio de um envio.
            $lock = Cache::lock('atelier:campanha:'.$campanha->id, 960);

            if (! $lock->get()) {
                continue;
            }

            try {
                $this->reconciliar($campanha, abandonarOrfaos: true);

                if ($campanha->estado !== 'em_processamento') {
                    $fechadas++;
                }
            } catch (Throwable $e) {
                // Uma campanha problemática (ou um deadlock) não pode impedir as restantes.
                Log::warning('Atelier: falha ao reconciliar uma campanha', [
                    'campanha_id' => $campanha->id,
                    'erro' => $e->getMessage(),
                ]);
            } finally {
                $lock->release();
            }
        }

        return $fechadas;
    }

    protected function alinharComAOutbox(Campanha $campanha): void
    {
        $campanha->envios()
            ->whereNotNull('notification_outbox_id')
            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE, CampanhaEnvio::ESTADO_INCERTO])
            ->chunkById(200, function ($envios) use ($campanha): void {
                // A consulta é sempre limitada à empresa da campanha.
                $outboxes = NotificationOutbox::withoutGlobalScopes()
                    ->where('empresa_id', $campanha->empresa_id)
                    ->whereIn('id', $envios->pluck('notification_outbox_id'))
                    ->get()
                    ->keyBy('id');

                foreach ($envios as $envio) {
                    $outbox = $outboxes->get($envio->notification_outbox_id);

                    if (! $outbox) {
                        continue;
                    }

                    // Updates condicionais: se a listener (ou outro reconciliador)
                    // mexeu no envio entretanto, este update simplesmente não aplica.
                    if (in_array($outbox->status, self::OUTBOX_FALHADA, true)) {
                        CampanhaEnvio::query()
                            ->whereKey($envio->id)
                            ->where('notification_outbox_id', $outbox->id)
                            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE, CampanhaEnvio::ESTADO_INCERTO])
                            ->update([
                                'estado' => CampanhaEnvio::ESTADO_FALHOU,
                                'erro' => $outbox->last_error
                                    ?: ($outbox->status === NotificationOutbox::STATUS_CANCELLED
                                        ? 'A notificação foi cancelada.'
                                        : 'A notificação terminou sem sucesso na Outbox.'),
                            ]);
                    } elseif (
                        in_array($envio->estado, [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_INCERTO], true)
                        && in_array($outbox->status, self::OUTBOX_ACEITE, true)
                    ) {
                        CampanhaEnvio::query()
                            ->whereKey($envio->id)
                            ->where('notification_outbox_id', $outbox->id)
                            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_INCERTO])
                            ->update(['estado' => CampanhaEnvio::ESTADO_ACEITE, 'erro' => null]);
                    } elseif (
                        $envio->estado === CampanhaEnvio::ESTADO_PENDENTE
                        && $outbox->status === NotificationOutbox::STATUS_UNKNOWN_RESULT
                    ) {
                        CampanhaEnvio::query()
                            ->whereKey($envio->id)
                            ->where('notification_outbox_id', $outbox->id)
                            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
                            ->update(['estado' => CampanhaEnvio::ESTADO_INCERTO, 'erro' => CampanhaEnvio::ERRO_INCERTO]);
                    } elseif (
                        $envio->estado === CampanhaEnvio::ESTADO_INCERTO
                        && $outbox->status !== NotificationOutbox::STATUS_UNKNOWN_RESULT
                    ) {
                        // Decisão humana de reenfileirar: o envio volta a aguardar o transporte.
                        CampanhaEnvio::query()
                            ->whereKey($envio->id)
                            ->where('notification_outbox_id', $outbox->id)
                            ->where('estado', CampanhaEnvio::ESTADO_INCERTO)
                            ->update(['estado' => CampanhaEnvio::ESTADO_PENDENTE, 'erro' => null]);
                    }
                }
            });
    }

    protected function abandonarEnviosOrfaos(Campanha $campanha): void
    {
        $campanha->envios()
            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
            ->whereNull('notification_outbox_id')
            ->where('updated_at', '<', now()->subMinutes(self::ENVIO_ORFAO_APOS_MINUTOS))
            ->update([
                'estado' => CampanhaEnvio::ESTADO_FALHOU,
                'erro' => 'O envio foi interrompido antes de a notificação ser registada. Usa «Reenviar falhados».',
            ]);
    }

    protected function fecharSeTerminou(Campanha $campanha): void
    {
        DB::transaction(function () use ($campanha): void {
            $bloqueada = Campanha::withoutGlobalScopes()
                ->whereKey($campanha->id)
                ->where('empresa_id', $campanha->empresa_id)
                ->lockForUpdate()
                ->first();

            if (! $bloqueada || $bloqueada->estado !== 'em_processamento') {
                return;
            }

            if ($bloqueada->envios()->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_INCERTO])->exists()) {
                return;
            }

            $temFalha = $bloqueada->envios()->where('estado', CampanhaEnvio::ESTADO_FALHOU)->exists();

            $bloqueada->update([
                'estado' => $temFalha ? 'concluida_com_falhas' : 'enviada',
                'enviada_em' => $bloqueada->enviada_em ?? now(),
            ]);
        });
    }
}
