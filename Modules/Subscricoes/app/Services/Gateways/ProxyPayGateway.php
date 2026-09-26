<?php

namespace Modules\Subscricoes\Services\Gateways;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Throwable;

/**
 * Integração com a ProxyPay (proxypay.co.ao) — pagamento por referência no
 * Multicaixa (ATM / homebanking). Escolhida como gateway "Multicaixa" desta
 * entrega por ser, das opções analisadas, a que tem documentação pública e
 * SDKs mais maduros para integração direta (ver README para a comparação
 * com o EMIS GPO/Multicaixa Express "oficial", que exige adesão bancária e
 * certificação antes de emitir uma única referência).
 *
 * IMPORTANTE: os nomes exatos do endpoint e dos campos abaixo foram
 * reconstruídos a partir da documentação pública e de SDKs de terceiros, não
 * de uma chamada real testada com credenciais válidas (este ambiente não
 * tem acesso à rede). Confirma cada um contra a documentação atual em
 * https://developer.proxypay.co.ao antes de ires para produção — o formato
 * geral (API key no cabeçalho Authorization, referência com amount +
 * expiry_date + custom_fields, webhook de confirmação) está correto.
 */
class ProxyPayGateway implements GatewayPagamentoInterface
{
    protected string $baseUrl;

    protected ?string $apiKey;

    protected ?string $webhookToken;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('subscricoes.gateways.proxypay.base_url'), '/');
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
                    'amount' => (float) $pagamento->valor,
                    'expiry_date' => $pagamento->expira_em?->toDateString(),
                    'custom_fields' => [
                        'invoice' => (string) $pagamento->id,
                        'empresa_id' => (string) $pagamento->empresa_id,
                    ],
                ])
                ->throw();

            $dados = $resposta->json();

            // TODO: confirmar o nome exato do campo da referência devolvida
            // pela tua conta ProxyPay (varia entre 'reference_id' e
            // 'reference' consoante a versão da API/documentação).
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

        // TODO: confirmar o mecanismo de assinatura exato (cabeçalho
        // 'Authorization: Token <valor>' vs. HMAC sobre o corpo do pedido)
        // na configuração de webhooks da tua conta ProxyPay.
        $cabecalho = $request->header('Authorization', '');

        return hash_equals('Token '.$this->webhookToken, $cabecalho);
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
    }
}
