<?php

namespace Modules\Subscricoes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Exceptions\PagamentoRejeitadoException;
use Modules\Subscricoes\Services\PagamentoService;
use Throwable;

class ProcessarPagamentoConfirmadoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(
        protected string $gatewayIdentificador,
        protected string $referenciaExterna,
        protected array $payloadBruto,
    ) {}

    public function handle(PagamentoService $pagamentoService): void
    {
        try {
            $pagamento = $pagamentoService->localizarPorReferencia($this->referenciaExterna, $this->payloadBruto);

            $pagamentoService->confirmar($pagamento, $this->payloadBruto);
        } catch (PagamentoRejeitadoException $e) {
            // Repetir não resolve: fica em failed_jobs para revisão manual.
            Log::critical('Pagamento recebido mas REJEITADO — requer revisão manual', [
                'gateway' => $this->gatewayIdentificador,
                'referencia_externa' => $this->referenciaExterna,
                'payload' => $this->payloadBruto,
                'erro' => $e->getMessage(),
            ]);

            $this->fail($e);
        } catch (Throwable $e) {
            Log::error('ProcessarPagamentoConfirmadoJob falhou', [
                'gateway' => $this->gatewayIdentificador,
                'referencia_externa' => $this->referenciaExterna,
                'tentativa' => $this->attempts(),
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
