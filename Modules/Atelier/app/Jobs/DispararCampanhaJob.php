<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Processa UMA campanha (requisito D.1) — disparado tanto pelo botão
 * "Enviar agora" (Admin\CampanhaController) como por
 * EnviarCampanhasAgendadasJob quando agendada_para chega. Recebe só o ID
 * (nunca o model) no construtor: um Job ShouldQueue é serializado para a
 * fila, e passar o ID evita problemas de serialização/dados desatualizados
 * se o worker só processar o Job mais tarde.
 *
 * Idempotente por desenho: a unique constraint em atelier_campanha_envios
 * (campanha_id, cliente_id), combinada com firstOrCreate() + verificação do
 * estado antes de reenviar, torna seguro correr este Job mais do que uma
 * vez para a mesma campanha (ex.: um retry automático da fila).
 *
 * Concorrência: um lock por campanha impede dois jobs simultâneos (clique
 * duplo, ou o agendador a re-despachar enquanto o anterior ainda corre).
 *
 * Reenvio: numa campanha já 'enviada' só se reprocessam os envios que
 * falharam/ficaram pendentes — nunca se alarga o público.
 *
 * Marketing: cada mensagem leva o link para o cliente deixar de receber
 * campanhas (Lei n.º 22/11), e o público é sempre só quem tem consentimento.
 */
class DispararCampanhaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(protected int $campanhaId)
    {
    }

    public function handle(
        TenantManager $tenantManager,
        CrmService $crmService,
        NotificadorClienteService $notificador,
        PortalLinkService $linkService,
    ): void {
        $lock = Cache::lock('atelier:campanha:'.$this->campanhaId, $this->timeout + 60);

        if (!$lock->get()) {
            // Já há um job a processar esta campanha.
            return;
        }

        try {
            $tenantManager->semTenant(fn () => $this->processar($crmService, $notificador, $linkService));
        } finally {
            $lock->release();
        }
    }

    protected function processar(CrmService $crmService, NotificadorClienteService $notificador, PortalLinkService $linkService): void
    {
        $campanha = Campanha::find($this->campanhaId);

        if (! $campanha || $campanha->estado === 'cancelada') {
            return;
        }

        $empresa = Empresa::find($campanha->empresa_id);

        if (! $empresa || ! $empresa->temAcesso() || ! $empresa->temServico('atelier')) {
            Log::info('Atelier: campanha não enviada — empresa sem acesso ou sem o serviço atelier', [
                'campanha_id' => $campanha->id,
                'empresa_id' => $campanha->empresa_id,
            ]);

            return;
        }

        $reenvio = in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true);
        $idsPorReenviar = collect();

        if ($reenvio) {
            $idsPorReenviar = $campanha->envios()->whereIn('estado', [CampanhaEnvio::ESTADO_FALHOU, CampanhaEnvio::ESTADO_PENDENTE])->pluck('cliente_id');

            if ($idsPorReenviar->isEmpty()) {
                return;
            }
        }

        $clientes = $crmService->resolverSegmento($campanha);

        if ($reenvio) {
            $clientes = $clientes->whereIn('id', $idsPorReenviar)->values();
        }

        $assunto = $campanha->assunto ?: $campanha->nome;

        foreach ($clientes as $cliente) {
            $envio = CampanhaEnvio::firstOrCreate(
                ['campanha_id' => $campanha->id, 'cliente_id' => $cliente->id],
                ['canal' => $campanha->canal, 'estado' => CampanhaEnvio::ESTADO_PENDENTE],
            );

            if (in_array($envio->estado, [CampanhaEnvio::ESTADO_ENVIADO, CampanhaEnvio::ESTADO_ACEITE], true)) {
                continue;
            }

            // Um envio telefónico só pode ganhar uma nova chave idempotente
            // depois de a tentativa anterior terminar definitivamente.
            if ($envio->notification_outbox_id !== null) {
                $outboxAnterior = $envio->notificationOutbox;

                if (! $outboxAnterior || ! in_array($outboxAnterior->status, [
                    NotificationOutbox::STATUS_FAILED,
                    NotificationOutbox::STATUS_DEAD_LETTER,
                ], true)) {
                    continue;
                }

                if ($envio->estado !== CampanhaEnvio::ESTADO_FALHOU) {
                    continue;
                }

                $envio->update([
                    'tentativa' => max(1, (int) $envio->tentativa) + 1,
                    'notification_outbox_id' => null,
                    'estado' => CampanhaEnvio::ESTADO_PENDENTE,
                    'erro' => null,
                ]);
                $envio->refresh();
            }

            $mensagem = $campanha->mensagem
                ."\n\nPara deixares de receber estas mensagens: ".$linkService->gerarLinkCancelarMarketing($cliente);

            try {
                if (in_array($campanha->canal, ['whatsapp', 'sms'], true)) {
                    $outbox = $notificador->enfileirarViaOutbox(
                        $cliente,
                        $campanha->canal,
                        $mensagem,
                        'atelier-campanha-'.$campanha->id.'-cliente-'.$cliente->id.'-tentativa-'.$envio->tentativa,
                    );

                    $envio->update([
                        'notification_outbox_id' => $outbox->id,
                        'estado' => $outbox->status === NotificationOutbox::STATUS_ACCEPTED
                            ? CampanhaEnvio::ESTADO_ACEITE
                            : CampanhaEnvio::ESTADO_PENDENTE,
                        'erro' => null,
                    ]);
                } else {
                    // O e-mail permanece síncrono até existir adaptador próprio na Outbox.
                    $notificador->notificarViaCanalUnico($cliente, $campanha->canal, $assunto, $mensagem);
                    $envio->update(['estado' => CampanhaEnvio::ESTADO_ENVIADO, 'enviado_em' => now(), 'erro' => null]);
                }
            } catch (NotificacaoException|Throwable $e) {
                $envio->update(['estado' => CampanhaEnvio::ESTADO_FALHOU, 'erro' => $e->getMessage()]);

                Log::warning('Atelier: falha ao enviar campanha para um cliente', [
                    'campanha_id' => $campanha->id,
                    'cliente_id' => $cliente->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        $temPendente = $campanha->envios()
            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
            ->exists();

        $temFalha = $campanha->envios()
            ->where('estado', CampanhaEnvio::ESTADO_FALHOU)
            ->exists();

        $campanha->update([
            'estado' => $temPendente
                ? 'em_processamento'
                : ($temFalha ? 'concluida_com_falhas' : 'enviada'),
            'enviada_em' => $temPendente ? null : ($campanha->enviada_em ?? now()),
        ]);
    }
}
