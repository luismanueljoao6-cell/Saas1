<?php

namespace Modules\Subscricoes\Services\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;

/**
 * Integração com a ProxyPay (pagamento por referência Multicaixa).
 *
 * IMPORTANTE: endpoint e nomes de campos foram reconstruídos da documentação
 * pública, não testados com credenciais reais. Confirma-os em
 * https://developer.proxypay.co.ao antes de produção — incluindo o campo do
 * valor pago no webhook (ver PagamentoService::extrairValorPago) e o
 * mecanismo de autenticação do webhook.
 */
class ProxyPayGateway implements GatewayPagamentoInterface
{
    protected string $baseUrl;

    protected ?string $apiKey;

    protected ?string $webhookToken;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('subscricoes.gateways.proxypay.base_url'), '/');
        $this->apiKey = config('subscricoes.gateways.proxypay.api_key');
        $this->webhookToken = config('subscricoes.gateways.proxypay.webhook_token');
    }

    public function identificador(): string
    {
        return 'proxypay';
    }

    public function gerarReferencia(Pagamento $pagamento): array
    {
        $this->garantirCredenciaisConfiguradas();

        try {
            $resposta = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(10)
                ->post("{$this->baseUrl}/references", [
                    'amount' => round((float) $pagamento->valor, 2),
                    'expiry_date' => $pagamento->expira_em?->toDateString(),
                    'custom_fields' => [
                        'invoice' => (string) $pagamento->id,
                        'empresa_id' => (string) $pagamento->empresa_id,
                    ],
                ])
                ->throw();
        } catch (RequestException $e) {
            Log::error('ProxyPay: falha HTTP ao gerar referência', [
                'pagamento_id' => $pagamento->id,
                'status' => $e->response?->status(),
                'corpo' => $e->response?->body(),
            ]);

            throw GatewayPagamentoException::falhaAoGerarReferencia(
                $this->identificador(),
                'resposta HTTP '.($e->response?->status() ?? 'desconhecida')
            );
        } catch (ConnectionException $e) {
            Log::error('ProxyPay: falha de ligação ao gerar referência', [
                'pagamento_id' => $pagamento->id,
                'erro' => $e->getMessage(),
            ]);

            throw GatewayPagamentoException::falhaAoGerarReferencia($this->identificador(), 'sem ligação ao gateway');
        }

        $dados = $resposta->json();
        $dados = is_array($dados) ? $dados : [];

        // TODO: confirmar o nome exato do campo ('reference_id' vs 'reference').
        $referencia = $dados['reference_id'] ?? $dados['reference'] ?? null;

        if (! $referencia) {
            throw GatewayPagamentoException::falhaAoGerarReferencia(
                $this->identificador(),
                'resposta do gateway não incluiu um número de referência reconhecível'
            );
        }

        return [
            'referencia_externa' => (string) $referencia,
            'payload' => $dados,
        ];
    }

    public function validarPedidoWebhook(Request $request): bool
    {
        if (! $this->webhookToken) {
            Log::warning('ProxyPay: webhook recebido sem PROXYPAY_WEBHOOK_TOKEN configurado — a rejeitar por omissão.');

            return false;
        }

        // TODO: confirmar o mecanismo (Authorization: Token vs HMAC do corpo).
        $cabecalho = (string) $request->header('Authorization', '');

        return $cabecalho !== '' && hash_equals('Token '.$this->webhookToken, $cabecalho);
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
            'payload' => $payload,
        ];
    }

    protected function garantirCredenciaisConfiguradas(): void
    {
        if (! $this->apiKey) {
            throw GatewayPagamentoException::falhaAoGerarReferencia(
                $this->identificador(),
                'PROXYPAY_API_KEY não está configurada'
            );
        }

        if ($this->baseUrl === '') {
            throw GatewayPagamentoException::falhaAoGerarReferencia(
                $this->identificador(),
                'URL base da ProxyPay não está configurado'
            );
        }
    }
}
