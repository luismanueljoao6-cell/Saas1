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
 * A ProxyPay não documenta assinatura HMAC dos webhooks: a autenticidade
 * assenta num segredo partilhado (cabeçalho OU parâmetro ?token= no URL
 * configurado no painel). Valida o formato do corpo do PUT (`end_datetime`)
 * no sandbox antes de produção.
 */
class ProxyPayGateway implements GatewayPagamentoInterface
{
    protected string $baseUrl;

    protected ?string $apiKey;

    protected ?string $webhookToken;

    protected ?string $entityId;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('subscricoes.gateways.proxypay.base_url'), '/');
        $this->apiKey = config('subscricoes.gateways.proxypay.api_key');
        $this->webhookToken = config('subscricoes.gateways.proxypay.webhook_token');
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
                'amount' => round((float) $pagamento->valor, 2),
                'end_datetime' => $pagamento->expira_em?->toDateString(),
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

    public function validarPedidoWebhook(Request $request): bool
    {
        if (! $this->webhookToken) {
            Log::warning('ProxyPay: webhook recebido sem PROXYPAY_WEBHOOK_TOKEN configurado — a rejeitar por omissão.');

            return false;
        }

        $candidatos = [
            (string) $request->header('Authorization', ''),
            'Token '.(string) $request->header('X-Webhook-Token', ''),
            'Token '.(string) $request->query('token', ''),
        ];

        $esperado = 'Token '.$this->webhookToken;

        foreach ($candidatos as $candidato) {
            if (hash_equals($esperado, $candidato)) {
                return true;
            }
        }

        return false;
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
