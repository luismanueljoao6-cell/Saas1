<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\Prova;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Corre de hora a hora (ver AtelierServiceProvider::agendarTarefas()).
 * Percorre provas de TODAS as empresas — por isso corre dentro do bypass
 * explícito do TenantManager, tal como VerificarSubscricoesExpiradasJob já
 * faz no módulo Subscricoes.
 */
class LembretesProvaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($notificador) {
            Prova::query()
                ->whereIn('estado', ['agendada', 'confirmada', 'reagendada'])
                ->whereNull('lembrete_enviado_em')
                ->where('data_hora_agendada', '>=', now())
                ->with(['pedido.cliente', 'pedido.empresa'])
                ->chunkById(100, function ($provas) use ($notificador) {
                    foreach ($provas as $prova) {
                        if (! $prova->precisaDeLembrete()) {
                            continue;
                        }

                        try {
                            $this->enviarLembrete($prova, $notificador);
                            $prova->update(['lembrete_enviado_em' => now()]);
                        } catch (Throwable $e) {
                            Log::error('Atelier: falha ao enviar lembrete de prova', [
                                'prova_id' => $prova->id,
                                'erro' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        });
    }

    protected function enviarLembrete(Prova $prova, NotificadorClienteService $notificador): void
    {
        $cliente = $prova->pedido?->cliente;

        if (! $cliente) {
            return;
        }

        $notificador->notificar(
            $cliente,
            "Lembrete: {$prova->tipoRotulo()} — {$prova->pedido->empresa->nome_comercial}",
            "Lembramos que tem a {$prova->tipoRotulo()} da sua peça agendada para ".
                $prova->data_hora_agendada->format('d/m/Y \à\s H:i').'.',
        );
    }
}
