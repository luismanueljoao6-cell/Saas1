<?php

namespace Modules\Faturacao\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
 * Corre sempre em fila. ATENÇÃO: DB_QUEUE_RETRY_AFTER tem de ser MAIOR que
 * $timeout (ver .env.example), senão o job é reentregue enquanto ainda corre.
 */
class GerarSafTJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(
        protected Empresa $empresa,
        protected string $dataInicio,
        protected string $dataFim,
    ) {
    }

    public function uniqueId(): string
    {
        return "saft:{$this->empresa->id}:{$this->dataInicio}:{$this->dataFim}";
    }

    public function handle(SafTExportService $safTExportService): void
    {
        $inicio = Carbon::createFromFormat('Y-m-d', $this->dataInicio)->startOfDay();
        $fim = Carbon::createFromFormat('Y-m-d', $this->dataFim)->endOfDay();

        $xml = $safTExportService->gerar($this->empresa, $inicio, $fim);

        $caminho = "faturacao/saft/{$this->empresa->id}/SAFT_{$inicio->toDateString()}_{$fim->toDateString()}.xml";
        Storage::disk('local')->put($caminho, $xml);

        Log::info('SAF-T (AO) gerado', [
            'empresa_id' => $this->empresa->id,
            'caminho' => $caminho,
            'tamanho_bytes' => strlen($xml),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Falha ao gerar SAF-T (AO)', [
            'empresa_id' => $this->empresa->id,
            'periodo' => "{$this->dataInicio} a {$this->dataFim}",
            'erro' => $e->getMessage(),
        ]);
    }
}
