<?php

namespace Modules\Subscricoes\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Throwable;

class SubscricaoService
{
    public function __construct(
        protected GatewayPagamentoInterface $gateway,
        protected TenantService $tenantService,
    ) {
    }

    /**
     * Cria uma subscrição pendente para a empresa e pede ao gateway ativo
     * uma referência de pagamento. A empresa só passa a estado "ativa"
     * quando o pagamento for confirmado (ver PagamentoService::confirmar()).
     *
     * @throws Throwable
     */
    public function iniciar(Empresa $empresa, Plano $plano): Pagamento
    {
        try {
            return DB::transaction(function () use ($empresa, $plano) {
                $subscricao = Subscricao::create([
                    'empresa_id' => $empresa->id,
                    'plano_id' => $plano->id,
                    'estado' => 'pendente',
                    'renovacao_automatica' => true,
                ]);

                $pagamento = Pagamento::create([
                    'empresa_id' => $empresa->id,
                    'subscricao_id' => $subscricao->id,
                    'gateway' => $this->gateway->identificador(),
                    'valor' => $plano->preco,
                    'moeda' => $plano->moeda,
                    'estado' => 'pendente',
                    'expira_em' => now()->addDays((int) Config::get('subscricoes.validade_referencia_dias', 3)),
                ]);

                $resultado = $this->gateway->gerarReferencia($pagamento);

                $pagamento->update([
                    'referencia_externa' => $resultado['referencia_externa'],
                    'payload_bruto' => $resultado['payload'],
                ]);

                $this->tenantService->marcarPendente($empresa);

                Log::info('Subscrição iniciada, aguarda pagamento', [
                    'empresa_id' => $empresa->id,
                    'plano_id' => $plano->id,
                    'pagamento_id' => $pagamento->id,
                    'referencia_externa' => $pagamento->referencia_externa,
                ]);

                return $pagamento->fresh(['subscricao.plano']);
            });
        } catch (Throwable $e) {
            Log::error('Falha ao iniciar subscrição', [
                'empresa_id' => $empresa->id,
                'plano_id' => $plano->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Cancela a renovação automática (a empresa mantém acesso até
     * termina_em, tal como qualquer subscrição "cancelada mas paga").
     */
    public function cancelarRenovacao(Subscricao $subscricao): Subscricao
    {
        $subscricao->update([
            'renovacao_automatica' => false,
            'cancelada_em' => now(),
        ]);

        Log::info('Renovação automática cancelada', ['subscricao_id' => $subscricao->id]);

        return $subscricao->fresh();
    }
}
