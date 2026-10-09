<?php

namespace Modules\Subscricoes\Jobs;

use DomainException;
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
 * imediato — o processamento em si corre em segundo plano.
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
        protected ?string $valorRecebido = null,
    ) {
    }

    public function handle(PagamentoService $pagamentoService): void
    {
        try {
            $pagamento = $pagamentoService->localizarPorReferencia($this->referenciaExterna);

            $pagamentoService->confirmar($pagamento, $this->payloadBruto, null, $this->valorRecebido);
        } catch (DomainException $e) {
            // Regra de negócio violada (ex.: valor pago inferior ao devido):
            // repetir não resolve — falha de vez e fica para revisão manual.
            Log::critical('Pagamento recebido rejeitado — requer revisão manual', [
                'gateway' => $this->gatewayIdentificador,
                'referencia_externa' => $this->referenciaExterna,
                'valor_recebido' => $this->valorRecebido,
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

            throw $e; // permite ao Laravel gerir novas tentativas / backoff
        }
    }
}
