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

    public function __construct(protected int $campanhaId) {}

    public function handle(
        TenantManager $tenantManager,
        CrmService $crmService,
        NotificadorClienteService $notificador,
        PortalLinkService $linkService,
    ): void {
        $lock = Cache::lock('atelier:campanha:'.$this->campanhaId, $this->timeout + 60);

        if (! $lock->get()) {
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

        $reenvio = $campanha->estado === 'enviada';
        $idsPorReenviar = collect();

        if ($reenvio) {
            $idsPorReenviar = $campanha->envios()->whereIn('estado', ['falhou', 'pendente'])->pluck('cliente_id');

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
                ['canal' => $campanha->canal, 'estado' => 'pendente'],
            );

            if ($envio->estado === 'enviado') {
                continue;
            }

            $mensagem = $campanha->mensagem
                ."\n\nPara deixares de receber estas mensagens: ".$linkService->gerarLinkCancelarMarketing($cliente);

            try {
                $notificador->notificarViaCanalUnico($cliente, $campanha->canal, $assunto, $mensagem);
                $envio->update(['estado' => 'enviado', 'enviado_em' => now(), 'erro' => null]);
            } catch (NotificacaoException|Throwable $e) {
                $envio->update(['estado' => 'falhou', 'erro' => $e->getMessage()]);

                Log::warning('Atelier: falha ao enviar campanha para um cliente', [
                    'campanha_id' => $campanha->id,
                    'cliente_id' => $cliente->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        $campanha->update([
            'estado' => 'enviada',
            'enviada_em' => $campanha->enviada_em ?? now(),
        ]);
    }
}
