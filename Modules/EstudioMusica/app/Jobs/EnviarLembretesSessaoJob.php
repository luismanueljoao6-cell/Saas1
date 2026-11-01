<?php

namespace Modules\\EstudioMusica\\Jobs;

use Illuminate\\Bus\\Queueable;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Foundation\\Bus\\Dispatchable;
use Illuminate\\Queue\\InteractsWithQueue;
use Illuminate\\Queue\\SerializesModels;
use Illuminate\\Support\\Facades\\Log;
use Modules\\Core\\Services\\TenantManager;
use Modules\\EstudioMusica\\Models\\SessaoEstudio;
use Modules\\EstudioMusica\\Services\\NotificacaoEstudioService;
use Modules\\EstudioMusica\\Support\\EmpresasDoEstudio;
use Throwable;

/**
 * Enfileira lembretes de sessão 24h e 2h antes. As colunas
 * lembrete_24h_enviado_em/lembrete_2h_enviado_em registam a hora em que
 * o lembrete foi aceite para a fila — NÃO confirmam entrega pelo canal.
 *
 * Percorre TODAS as empresas — precisa por isso do bypass explícito do
 * TenantManager, tal como VerificarSubscricoesExpiradasJob (Subscricoes).
 */
class EnviarLembretesSessaoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager, NotificacaoEstudioService $notificacao): void
    {
        $tenantManager->semTenant(function () use ($notificacao): void {
            // Só estúdios com o serviço ativo e acesso: os outros já não são clientes.
            $empresas = EmpresasDoEstudio::ativas();

            $this->enviarJanela(24, 'lembrete_24h_enviado_em', $notificacao, $empresas);
            $this->enviarJanela(2, 'lembrete_2h_enviado_em', $notificacao, $empresas);
        });
    }

    /** @param  array<int, int>  $empresas */
    protected function enviarJanela(int $horasAntes, string $coluna, NotificacaoEstudioService $notificacao, array $empresas): void
    {
        $alvo = now()->addHours($horasAntes);

        SessaoEstudio::query()
            ->whereIn('empresa_id', $empresas)
            ->whereNotIn('estado', ['cancelada', 'concluida'])
            ->whereNull($coluna)
            ->whereBetween('inicio_previsto', [
                $alvo->clone()->subMinutes(30),
                $alvo->clone()->addMinutes(30),
            ])
            ->with('cliente')
            ->chunkById(100, function ($sessoes) use ($coluna, $horasAntes, $notificacao): void {
                foreach ($sessoes as $sessao) {
                    try {
                        if ($notificacao->lembreteSessao($sessao, $horasAntes)) {
                            // Marca apenas depois de a fila aceitar o dispatch.
                            $sessao->update([$coluna => now()]);
                        } else {
                            Log::warning('EstudioMusica: lembrete não enfileirado — cliente sem contacto elegível.', [
                                'sessao_id' => $sessao->id,
                                'empresa_id' => $sessao->empresa_id,
                                'horas_antes' => $horasAntes,
                            ]);
                        }
                    } catch (Throwable $e) {
                        Log::error('EstudioMusica: falha ao enfileirar lembrete de sessão', [
                            'sessao_id' => $sessao->id,
                            'horas_antes' => $horasAntes,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
