<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Models\CampanhaReenvio;
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
 * falharam/ficaram pendentes — nunca se alarga o público. Só um pedido
 * manual aceite (CampanhaReenvio, identificado por $reenvioToken) autoriza
 * esse reenvio: um job sem token, repetido, obsoleto ou duplicado nunca
 * reenvia nada. Cada pedido autoriza no máximo UMA execução.
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

    public function __construct(protected int $campanhaId, protected ?string $reenvioToken = null) {}

    public function handle(
        TenantManager $tenantManager,
        CrmService $crmService,
        NotificadorClienteService $notificador,
        PortalLinkService $linkService,
    ): void {
        $lock = Cache::lock('atelier:campanha:'.$this->campanhaId, $this->timeout + 60);

        if (! $lock->get()) {
            // Já há um job a processar esta campanha. Um pedido de reenvio
            // não pode ficar aberto sem ninguém o tratar: volta a tentar mais
            // tarde ou, esgotadas as tentativas, fecha-se como ignorado.
            $this->adiarOuIgnorarPedido();

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
        $pedido = $this->reivindicarPedidoDeReenvio();

        // Com token, só corre se o pedido existir e ainda estiver aberto:
        // um job obsoleto (pedido já usado, expirado ou inexistente) sai aqui.
        if ($this->reenvioToken !== null && $pedido === null) {
            return;
        }

        $motivoIgnorado = $this->executar($crmService, $notificador, $linkService, $pedido !== null);

        $pedido?->fechar(
            $motivoIgnorado === null ? CampanhaReenvio::CONCLUIDO : CampanhaReenvio::IGNORADO,
            $motivoIgnorado,
        );
    }

    /**
     * Marca o pedido como iniciado sob lock de linha. Devolve null quando o
     * job não tem token (envio normal) ou quando o pedido não é utilizável.
     * Uma repetição do MESMO job (retry da fila após falha) retoma o pedido
     * 'iniciado'; um pedido fechado nunca volta a abrir.
     */
    protected function reivindicarPedidoDeReenvio(): ?CampanhaReenvio
    {
        if ($this->reenvioToken === null) {
            return null;
        }

        return DB::transaction(function (): ?CampanhaReenvio {
            $pedido = CampanhaReenvio::query()
                ->where('campanha_id', $this->campanhaId)
                ->where('token', $this->reenvioToken)
                ->lockForUpdate()
                ->first();

            if (! $pedido || ! in_array($pedido->estado, [CampanhaReenvio::ACEITE, CampanhaReenvio::INICIADO], true)) {
                Log::info('Atelier: job de reenvio ignorado — pedido inexistente, já usado ou expirado', [
                    'campanha_id' => $this->campanhaId,
                    'estado_pedido' => $pedido?->estado,
                ]);

                return null;
            }

            if ($pedido->estado === CampanhaReenvio::ACEITE) {
                $pedido->update(['estado' => CampanhaReenvio::INICIADO, 'iniciado_em' => now()]);
            }

            return $pedido;
        });
    }

    protected function adiarOuIgnorarPedido(): void
    {
        if ($this->reenvioToken === null) {
            return;
        }

        if ($this->job !== null && $this->attempts() < $this->tries) {
            $this->release(30);

            return;
        }

        $this->fecharPedido(CampanhaReenvio::IGNORADO, 'A campanha continuou ocupada por outro job.');
    }

    /** Chamado pela fila quando as tentativas se esgotam: nunca deixar o pedido aberto para sempre. */
    public function failed(Throwable $exception): void
    {
        $this->fecharPedido(CampanhaReenvio::FALHADO, $exception->getMessage());
    }

    protected function fecharPedido(string $estado, ?string $motivo): void
    {
        if ($this->reenvioToken === null) {
            return;
        }

        app(TenantManager::class)->semTenant(function () use ($estado, $motivo): void {
            CampanhaReenvio::query()
                ->where('campanha_id', $this->campanhaId)
                ->where('token', $this->reenvioToken)
                ->first()
                ?->fechar($estado, $motivo);
        });
    }

    protected function executar(
        CrmService $crmService,
        NotificadorClienteService $notificador,
        PortalLinkService $linkService,
        bool $reenvioAutorizado,
    ): ?string {
        $campanha = Campanha::find($this->campanhaId);

        if (! $campanha || $campanha->estado === 'cancelada') {
            return 'Campanha inexistente ou cancelada.';
        }

        $empresa = Empresa::find($campanha->empresa_id);

        if (! $empresa || ! $empresa->temAcesso() || ! $empresa->temServico('atelier')) {
            Log::info('Atelier: campanha não enviada — empresa sem acesso ou sem o serviço atelier', [
                'campanha_id' => $campanha->id,
                'empresa_id' => $campanha->empresa_id,
            ]);

            return 'Empresa sem acesso ou sem o serviço atelier.';
        }

        // Reconcilia estados terminais da Outbox mesmo quando o evento
        // de sincronização se perdeu. A consulta é sempre limitada à empresa
        // da campanha; não provoca reenvios automáticos.
        $enviosComOutbox = $campanha->envios()
            ->whereNotNull('notification_outbox_id')
            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE])
            ->get();

        foreach ($enviosComOutbox as $envioOutbox) {
            $outbox = NotificationOutbox::withoutGlobalScopes()
                ->where('empresa_id', $campanha->empresa_id)
                ->whereKey($envioOutbox->notification_outbox_id)
                ->first();

            if (! $outbox || ! in_array($outbox->status, [
                NotificationOutbox::STATUS_FAILED,
                NotificationOutbox::STATUS_DEAD_LETTER,
            ], true)) {
                continue;
            }

            $envioOutbox->update([
                'estado' => CampanhaEnvio::ESTADO_FALHOU,
                'erro' => $outbox->last_error ?: 'A notificação terminou sem sucesso na Outbox.',
            ]);
        }

        $temPendenteReconhecido = $campanha->envios()
            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
            ->exists();

        if ($campanha->estado === 'em_processamento' && ! $temPendenteReconhecido) {
            $temFalhaReconhecida = $campanha->envios()
                ->where('estado', CampanhaEnvio::ESTADO_FALHOU)
                ->exists();

            $campanha->update([
                'estado' => $temFalhaReconhecida ? 'concluida_com_falhas' : 'enviada',
                'enviada_em' => $campanha->enviada_em ?? now(),
            ]);
            $campanha->refresh();
        }

        $concluida = in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true);

        // Um job repetido pela fila/agendador nunca pode transformar uma
        // reconciliação terminal num novo envio. Só o botão de reenvio manual
        // autoriza uma nova tentativa para campanhas já concluídas.
        if (! $reenvioAutorizado && $concluida) {
            return null;
        }

        // Um reenvio autorizado só actua sobre uma campanha concluída. Se entretanto
        // deixou de o estar, não há nada a reenviar — e nunca se alarga o público.
        if ($reenvioAutorizado && ! $concluida) {
            return 'A campanha deixou de estar concluída: nada a reenviar.';
        }

        $reenvio = $reenvioAutorizado;
        $idsPorReenviar = collect();

        if ($reenvio) {
            $idsPorReenviar = $campanha->envios()->whereIn('estado', [CampanhaEnvio::ESTADO_FALHOU, CampanhaEnvio::ESTADO_PENDENTE])->pluck('cliente_id');

            if ($idsPorReenviar->isEmpty()) {
                return 'Não havia envios falhados ou pendentes para repetir.';
            }
        }

        $clientes = $crmService->resolverSegmento($campanha);

        if ($reenvio) {
            $clientes = $clientes->whereIn('id', $idsPorReenviar)->values();
        }

        $assunto = $campanha->assunto ?: $campanha->nome;

        foreach ($clientes as $cliente) {
            // A tentativa é indicada de forma explícita: o valor por omissão da
            // coluna não é carregado para o modelo acabado de criar, e a chave
            // idempotente da Outbox tem de ser igual em qualquer execução do job.
            $envio = CampanhaEnvio::firstOrCreate(
                ['campanha_id' => $campanha->id, 'cliente_id' => $cliente->id],
                ['canal' => $campanha->canal, 'estado' => CampanhaEnvio::ESTADO_PENDENTE, 'tentativa' => 1],
            );

            if (in_array($envio->estado, [CampanhaEnvio::ESTADO_ENVIADO, CampanhaEnvio::ESTADO_ACEITE], true)) {
                continue;
            }

            // Uma tentativa falhada só volta a ser tentada num reenvio autorizado:
            // nunca por um job repetido enquanto a campanha ainda processa.
            if ($envio->estado === CampanhaEnvio::ESTADO_FALHOU && ! $reenvio) {
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
            } catch (Throwable $e) {
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

        return null;
    }
}
