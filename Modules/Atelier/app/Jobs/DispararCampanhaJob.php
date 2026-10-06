<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
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
 */
class DispararCampanhaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(protected int $campanhaId)
    {
    }

    public function handle(TenantManager $tenantManager, CrmService $crmService, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($crmService, $notificador) {
            $campanha = Campanha::find($this->campanhaId);

            if (! $campanha || $campanha->estado === 'enviada') {
                return;
            }

            $assunto = $campanha->assunto ?: $campanha->nome;

            foreach ($crmService->resolverSegmento($campanha) as $cliente) {
                $envio = CampanhaEnvio::firstOrCreate(
                    ['campanha_id' => $campanha->id, 'cliente_id' => $cliente->id],
                    ['canal' => $campanha->canal, 'estado' => 'pendente'],
                );

                if ($envio->estado === 'enviado') {
                    continue;
                }

                try {
                    $notificador->notificarViaCanalUnico($cliente, $campanha->canal, $assunto, $campanha->mensagem);
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

            $campanha->update(['estado' => 'enviada', 'enviada_em' => now()]);
        });
    }
}
