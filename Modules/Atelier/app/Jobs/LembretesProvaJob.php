<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\Prova;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Corre de hora a hora (ver AtelierServiceProvider::agendarTarefas()).
 *
 * A consulta global descobre apenas os pares ID/empresa_id. Cada prova é
 * recarregada e processada dentro de comEmpresa(), para que as relações
 * Pedido, Cliente e Empresa respeitem os scopes do tenant proprietário.
 */
class LembretesProvaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager, NotificadorClienteService $notificador): void
    {
        DB::table('atelier_provas')
            ->select(['id', 'empresa_id'])
            ->whereIn('estado', ['agendada', 'confirmada', 'reagendada'])
            ->whereNull('lembrete_enviado_em')
            ->where('data_hora_agendada', '>=', now())
            ->orderBy('id')
            ->chunkById(100, function ($identificadores) use ($tenantManager, $notificador): void {
                foreach ($identificadores as $identificador) {
                    $empresaId = (int) $identificador->empresa_id;
                    $provaId = (int) $identificador->id;

                    if ($empresaId < 1 || $provaId < 1) {
                        continue;
                    }

                    $tenantManager->comEmpresa($empresaId, function () use ($empresaId, $provaId, $notificador): void {
                        $prova = Prova::query()
                            ->where('empresa_id', $empresaId)
                            ->with(['pedido.cliente', 'pedido.empresa'])
                            ->find($provaId);

                        if (! $prova || (int) $prova->empresa_id !== $empresaId) {
                            return;
                        }

                        $pedido = $prova->pedido;
                        $empresa = $pedido?->empresa;

                        // Uma FK simples não prova que a prova e o pedido pertencem
                        // à mesma empresa. A relação scoped deve existir e coincidir.
                        if (
                            ! $pedido
                            || (int) $pedido->empresa_id !== $empresaId
                            || ! $empresa
                            || (int) $empresa->id !== $empresaId
                            || $pedido->estaFinalizado()
                            || ! $empresa->temAcesso()
                            || ! $empresa->temServico('atelier')
                            || ! $prova->precisaDeLembrete()
                        ) {
                            return;
                        }

                        try {
                            if ($this->enviarLembrete($prova, $notificador)) {
                                $prova->update(['lembrete_enviado_em' => now()]);
                            } else {
                                Log::warning('Atelier: nenhum canal aceitou o lembrete de prova', [
                                    'empresa_id' => $empresaId,
                                    'prova_id' => $provaId,
                                ]);
                            }
                        } catch (Throwable $e) {
                            Log::error('Atelier: falha ao enviar lembrete de prova', [
                                'empresa_id' => $empresaId,
                                'prova_id' => $provaId,
                                'erro' => $e->getMessage(),
                            ]);
                        }
                    });
                }
            });
    }

    protected function enviarLembrete(Prova $prova, NotificadorClienteService $notificador): bool
    {
        $cliente = $prova->pedido?->cliente;

        if (! $cliente || (int) $cliente->empresa_id !== (int) $prova->empresa_id) {
            return false;
        }

        return $notificador->notificar(
            $cliente,
            "{$prova->tipoRotulo()} — {$prova->pedido->empresa->nome_comercial}",
            "Lembramos que tem a {$prova->tipoRotulo()} da sua peça agendada para ".
                $prova->data_hora_agendada->format('d/m/Y \\à\\s H:i').'.',
        );
    }
}
