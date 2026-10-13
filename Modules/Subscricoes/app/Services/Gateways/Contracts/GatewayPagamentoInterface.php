<?php

namespace Modules\Subscricoes\Services\Gateways\Contracts;

use Illuminate\Http\Request;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;

/**
 * Abstração deliberada: o resto do módulo (SubscricaoService, Jobs,
 * Controllers) nunca fala diretamente com a ProxyPay, o EMIS GPO ou
 * qualquer outro fornecedor — só com esta interface. Trocar de gateway, ou
 * suportar vários em simultâneo ("outros métodos alternativos", como pede
 * o requisito original), resume-se a escrever uma nova classe que
 * implemente isto e registá-la no SubscricoesServiceProvider.
 */
interface GatewayPagamentoInterface
{
    /**
     * Identificador curto do gateway (ex.: 'proxypay'), guardado em
     * pagamentos.gateway.
     */
    public function identificador(): string;

    /**
     * Pede ao gateway uma referência de pagamento para o valor do
     * Pagamento indicado. Deve devolver, no mínimo, a referência externa;
     * o payload completo da resposta é guardado tal e qual em
     * payload_bruto para auditoria.
     *
     * @return array{referencia_externa: string, payload: array<string, mixed>}
     *
     * @throws GatewayPagamentoException
     */
    public function gerarReferencia(Pagamento $pagamento): array;

    /**
     * Confirma que o pedido HTTP recebido é mesmo do gateway (assinatura,
     * token partilhado, IP, etc. — conforme o mecanismo do fornecedor) e
     * não uma chamada forjada. Chamado pelo middleware
     * VerificarAssinaturaWebhook antes de qualquer processamento.
     */
    public function validarPedidoWebhook(Request $request): bool;

    /**
     * Extrai do payload do webhook os dados necessários para localizar e
     * confirmar o Pagamento correspondente.
     *
     * @return array{referencia_externa: string, estado: string, payload: array<string, mixed>}
     */
    public function interpretarNotificacao(Request $request): array;
}
