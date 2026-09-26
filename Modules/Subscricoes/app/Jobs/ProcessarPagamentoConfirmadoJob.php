<?php

namespace Modules\Subscricoes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Services\PagamentoService;
use Throwable;

/**
 * O controller do webhook despacha este Job e responde 200 ao gateway de
 * imediato — o processamento em si (que pode envolver várias escritas na
 * base de dados e o envio de notificações) corre em segundo plano. Isto
 * evita que uma lentidão nossa faça o gateway considerar o webhook como
 * falhado e tentar reenviá-lo desnecessariamente.
 */
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
    ) {
    }

    public function handle(PagamentoService $pagamentoService): void
    {
        try {
            $pagamento = $pagamentoService->localizarPorReferencia($this->referenciaExterna);

            $pagamentoService->confirmar($pagamento, $this->payloadBruto);
        } catch (Throwable $e) {
            Log::error('ProcessarPagamentoConfirmadoJob falhou', [
                'gateway' => $this->gatewayIdentificador,
                'referencia_externa' => $this->referenciaExterna,
                'tentativa' => $this->attempts(),
                'erro' => $e->getMessage(),
            ]);

            throw $e; // permite ao Laravel gerir novas tentativas / backoff
        }
    }
}
