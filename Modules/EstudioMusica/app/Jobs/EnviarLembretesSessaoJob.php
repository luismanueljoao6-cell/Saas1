<?php

namespace Modules\EstudioMusica\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;
use Throwable;

/**
 * "Lembrete de Sessão de Gravação 24h e 2h antes" (secção D). Pensado
 * para correr de hora a hora (ver EstudioMusicaServiceProvider) — por
 * isso cada janela tem tolerância de +/-30min em vez de exigir que o job
 * corra exatamente ao segundo do alvo. As colunas
 * lembrete_24h_enviado_em/lembrete_2h_enviado_em (não um booleano genérico)
 * garantem que cada sessão recebe CADA lembrete no máximo uma vez, mesmo
 * que o job corra várias vezes dentro da mesma janela de tolerância.
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
            $this->enviarJanela(24, 'lembrete_24h_enviado_em', $notificacao);
            $this->enviarJanela(2, 'lembrete_2h_enviado_em', $notificacao);
        });
    }

    protected function enviarJanela(int $horasAntes, string $coluna, NotificacaoEstudioService $notificacao): void
    {
        $alvo = now()->addHours($horasAntes);

        SessaoEstudio::query()
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
                        $notificacao->lembreteSessao($sessao, $horasAntes);
                        $sessao->update([$coluna => now()]);
                    } catch (Throwable $e) {
                        Log::error('EstudioMusica: falha ao enviar lembrete de sessão', [
                            'sessao_id' => $sessao->id,
                            'horas_antes' => $horasAntes,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
