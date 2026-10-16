<?php

namespace Modules\Subscricoes\Http\Controllers\Webhooks;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Jobs\ProcessarPagamentoConfirmadoJob;
use Modules\Subscricoes\Models\WebhookEvento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Throwable;

/**
 * A autenticidade do pedido já foi confirmada pelo middleware
 * VerificarAssinaturaWebhook.
 *
 * Política de resposta (a ProxyPay reenvia webhooks e exige tratamento
 * idempotente):
 *  - Só se responde 2xx depois de o evento estar GUARDADO na tabela
 *    webhook_eventos. Se a gravação falhar, responde-se 503 e o gateway
 *    reenvia — nunca se confirma um evento que não ficou recuperável.
 *  - Payload autenticado mas ilegível: fica guardado como 'ilegivel' para
 *    revisão (comando subscricoes:reconciliar) e responde-se 200, porque
 *    reenviar o mesmo corpo não o tornaria legível.
 *  - Falha ao despachar o job (fila em baixo): o evento fica 'recebido' e a
 *    reconciliação reprocessa-o; responde-se 200 porque já é recuperável.
 *  - Reenvio de um evento já tratado: 200 sem reprocessar.
 *
 * É obrigatório ter `php artisan queue:work` e o scheduler ativos.
 */
class ProxyPayWebhookController extends Controller
{
    public function __construct(protected GatewayPagamentoInterface $gateway) {}

    public function __invoke(Request $request): JsonResponse
    {
        $gateway = $this->gateway->identificador();
        $corpo = (string) $request->getContent();

        try {
            [$evento, $novo] = $this->guardarEvento($gateway, $corpo);
        } catch (Throwable $e) {
            Log::critical('Webhook autenticado NÃO guardado — a pedir reenvio ao gateway', [
                'gateway' => $gateway,
                'erro' => $e->getMessage(),
            ]);

            return response()->json(['recebido' => false], 503);
        }

        if (
            ! $novo
            && in_array($evento->estado, [WebhookEvento::PROCESSADO, WebhookEvento::REJEITADO, WebhookEvento::ILEGIVEL, WebhookEvento::RESOLVIDO], true)
        ) {
            return response()->json(['recebido' => true, 'duplicado' => true]);
        }

        try {
            $notificacao = $this->gateway->interpretarNotificacao($request);
        } catch (Throwable $e) {
            $evento->update([
                'estado' => WebhookEvento::ILEGIVEL,
                'motivo_falha' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Log::critical('Webhook autenticado mas não interpretável — guardado para revisão manual', [
                'gateway' => $gateway,
                'evento_id' => $evento->id,
                'erro' => $e->getMessage(),
            ]);

            return response()->json(['recebido' => true]);
        }

        $evento->update([
            'referencia_externa' => $notificacao['referencia_externa'],
            'valor' => $notificacao['valor'] ?? null,
        ]);

        try {
            ProcessarPagamentoConfirmadoJob::dispatch(
                $gateway,
                $notificacao['referencia_externa'],
                $notificacao['payload'],
                $notificacao['valor'] ?? null,
                $evento->id,
            );
        } catch (Throwable $e) {
            Log::critical('Webhook guardado, mas o processamento falhou/não foi despachado — a reconciliação reprocessa', [
                'gateway' => $gateway,
                'evento_id' => $evento->id,
                'referencia_externa' => $notificacao['referencia_externa'],
                'erro' => $e->getMessage(),
            ]);
        }

        return response()->json(['recebido' => true]);
    }

    /**
     * @return array{0: WebhookEvento, 1: bool} [evento, é novo]
     */
    protected function guardarEvento(string $gateway, string $corpo): array
    {
        $chave = hash('sha256', $gateway."\n".$corpo);

        try {
            $evento = WebhookEvento::query()->create([
                'gateway' => $gateway,
                'chave_evento' => $chave,
                'corpo_bruto' => $corpo,
                'estado' => WebhookEvento::RECEBIDO,
            ]);

            return [$evento, true];
        } catch (UniqueConstraintViolationException) {
            $evento = WebhookEvento::query()->where('chave_evento', $chave)->firstOrFail();
            $evento->increment('recebimentos');

            return [$evento, false];
        }
    }
}
