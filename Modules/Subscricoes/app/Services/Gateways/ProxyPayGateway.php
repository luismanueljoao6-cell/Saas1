<?php

namespace Modules\Subscricoes\Services\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Throwable;

/**
 * ProxyPay API v2 (https://developer.proxypay.co.ao/v2/): pagamento por
 * referência Multicaixa.
 *
 * Fluxo:
 *  1. POST /reference_ids       -> devolve um ID de referência livre.
 *  2. PUT  /references/{id}     -> regista a referência (valor + data de fim).
 *  3. O cliente paga no ATM/Multicaixa Express; a ProxyPay notifica o webhook
 *     configurado no painel (payload do pagamento, com `reference_id` e `amount`).
 *
 * Autenticação: `Authorization: Token <API_KEY>` e
 * `Accept: application/vnd.proxypay.v2+json` (NÃO é Bearer).
 * Sandbox: PROXYPAY_BASE_URL=https://api.sandbox.proxypay.co.ao
 *
 * Os webhooks da ProxyPay v2 são autenticados com HMAC-SHA-256 do corpo
 * HTTP original, usando a API key como segredo e a assinatura hexadecimal
 * no cabeçalho X-Signature. Nunca aceitar tokens na query string.
 */
class ProxyPayGateway implements GatewayPagamentoInterface
{
    protected string $baseUrl;

    protected ?string $apiKey;

    protected ?string $entityId;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('subscricoes.gateways.proxypay.base_url'), '/');
        $this->apiKey = config('subscricoes.gateways.proxypay.api_key');
        $this->entityId = config('subscricoes.gateways.proxypay.entity_id');
    }

    public function identificador(): string
    {
        return 'proxypay';
    }

    public function gerarReferencia(Pagamento $pagamento): array
    {
        $this->garantirCredenciaisConfiguradas();

        try {
            // 1) ID de referência livre (corpo: número, por vezes entre aspas).
            $idResposta = $this->http()->post("{$this->baseUrl}/reference_ids")->throw();
            $referencia = trim((string) $idResposta->body(), " \t\n\r\0\x0B\"");

            if (! preg_match('/^\d{6,12}$/', $referencia)) {
                throw GatewayPagamentoException::falhaAoGerarReferencia(
                    $this->identificador(),
                    'a ProxyPay devolveu um ID de referência inválido'
                );
            }

            // 2) Registo da referência (idempotente para o mesmo ID).
            $dados = [
                'amount' => (string) $pagamento->valor,
                'end_datetime' => $pagamento->expira_em?->toIso8601String(),
                'custom_fields' => [
                    'pagamento_id' => (string) $pagamento->id,
                    'empresa_id' => (string) $pagamento->empresa_id,
                ],
            ];

            $this->http()->put("{$this->baseUrl}/references/{$referencia}", $dados)->throw();

            return [
                'referencia_externa' => $referencia,
                'payload' => [
                    'reference_id' => $referencia,
                    'entity_id' => $this->entityId,
                    'amount' => $dados['amount'],
                    'end_datetime' => $dados['end_datetime'],
                ],
            ];
        } catch (GatewayPagamentoException $e) {
            throw $e;
        } catch (RequestException $e) {
            Log::error('ProxyPay: falha HTTP ao gerar referência', [
                'pagamento_id' => $pagamento->id,
                'status' => $e->response?->status(),
                'corpo' => $e->response?->body(),
            ]);

            throw GatewayPagamentoException::falhaAoGerarReferencia($this->identificador(), $e->getMessage());
        } catch (Throwable $e) {
            Log::error('ProxyPay: erro inesperado ao gerar referência', [
                'pagamento_id' => $pagamento->id,
                'erro' => $e->getMessage(),
            ]);

            throw GatewayPagamentoException::falhaAoGerarReferencia($this->identificador(), $e->getMessage());
        }
    }

    public function cancelarReferencia(Pagamento $pagamento): void
    {
        if (! $pagamento->referencia_externa) {
            return;
        }

        $this->garantirCredenciaisConfiguradas();

        try {
            $resposta = $this->http()->delete(
                "{$this->baseUrl}/references/{$pagamento->referencia_externa}"
            );

            if ($resposta->status() === 404) {
                return;
            }

            $resposta->throw();
        } catch (RequestException $e) {
            Log::error('ProxyPay: falha ao cancelar referência de pagamento', [
                'pagamento_id' => $pagamento->id,
                'referencia_externa' => $pagamento->referencia_externa,
                'status' => $e->response?->status(),
                'erro' => $e->getMessage(),
            ]);

            throw GatewayPagamentoException::falhaAoGerarReferencia(
                $this->identificador(),
                'não foi possível cancelar a referência externa: '.$e->getMessage()
            );
        }
    }

    public function validarPedidoWebhook(Request $request): bool
    {
        if (! $this->apiKey) {
            Log::warning('ProxyPay: webhook rejeitado porque PROXYPAY_API_KEY não está configurada.');

            return false;
        }

        $assinatura = strtolower(trim((string) $request->header('X-Signature', '')));

        if (! preg_match('/^[a-f0-9]{64}$/', $assinatura)) {
            return false;
        }

        // Importante: assinar os bytes originais, não o JSON descodificado e
        // novamente serializado, pois espaços/ordem das propriedades alteram o HMAC.
        $esperada = hash_hmac('sha256', $request->getContent(), $this->apiKey);

        return hash_equals($esperada, $assinatura);
    }

    public function interpretarNotificacao(Request $request): array
    {
        $payload = $request->json()->all();

        $referencia = $payload['reference_id'] ?? $payload['reference'] ?? null;

        if (! $referencia) {
            throw GatewayPagamentoException::pagamentoNaoEncontrado('(referência ausente no payload do webhook)');
        }

        return [
            'referencia_externa' => (string) $referencia,
            'estado' => 'confirmado',
            // Valor efetivamente pago: o PagamentoService compara-o com o devido.
            'valor' => isset($payload['amount']) ? (string) $payload['amount'] : null,
            'payload' => $payload,
        ];
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Token '.$this->apiKey,
            'Accept' => 'application/vnd.proxypay.v2+json',
        ])->asJson()->timeout(10);
    }

    protected function garantirCredenciaisConfiguradas(): void
    {
        if (! $this->apiKey) {
            throw GatewayPagamentoException::falhaAoGerarReferencia(
                $this->identificador(),
                'PROXYPAY_API_KEY não está configurada'
            );
        }
    }
}
