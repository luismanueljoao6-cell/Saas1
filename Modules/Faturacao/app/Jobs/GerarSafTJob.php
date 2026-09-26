<?php

namespace Modules\Faturacao\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Services\SafTExportService;
use Throwable;

/**
 * Gerar o SAF-T pode envolver milhares de faturas — corre sempre em fila,
 * nunca no próprio pedido HTTP (era um requisito explícito do pedido
 * original: "a exportação do SAF-T... não bloquear o servidor").
 */
class GerarSafTJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public function __construct(
        protected Empresa $empresa,
        protected string $dataInicio,
        protected string $dataFim,
    ) {
    }

    public function handle(SafTExportService $safTExportService): void
    {
        try {
            $xml = $safTExportService->gerar(
                $this->empresa,
                Carbon::parse($this->dataInicio)->startOfDay(),
                Carbon::parse($this->dataFim)->endOfDay(),
            );

            $caminho = "faturacao/saft/{$this->empresa->id}/SAFT_{$this->dataInicio}_{$this->dataFim}.xml";
            Storage::disk('local')->put($caminho, $xml);

            Log::info('SAF-T (AO) gerado', [
                'empresa_id' => $this->empresa->id,
                'periodo' => "{$this->dataInicio} a {$this->dataFim}",
                'caminho' => $caminho,
                'tamanho_bytes' => strlen($xml),
            ]);

            // Próximo passo natural aqui: notificar o utilizador que pediu
            // a exportação com um link de download — deixado de fora desta
            // entrega para não presumir onde guardas ficheiros gerados em
            // produção (local vs. S3 vs. outro disco).
        } catch (Throwable $e) {
            Log::error('Falha ao gerar SAF-T (AO)', [
                'empresa_id' => $this->empresa->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
