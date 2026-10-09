<?php

namespace Modules\Subscricoes\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Notifications\RenovacaoProximaNotification;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Throwable;

class SubscricaoService
{
    public function __construct(
        protected GatewayPagamentoInterface $gateway,
        protected TenantService $tenantService,
        protected TenantManager $tenantManager,
    ) {}

    /**
     * Cria uma subscrição pendente e pede ao gateway uma referência de
     * pagamento. Se já existir uma referência pendente e ainda válida para
     * o MESMO plano, devolve-a em vez de gerar outra (cliques repetidos em
     * "Subscrever" não acumulam referências).
     *
     * O estado da empresa só muda aqui se ela já estiver SEM acesso; uma
     * empresa em trial ou com subscrição ativa mantém o acesso até o
     * pagamento ser confirmado (ver PagamentoService::confirmar()).
     *
     * @throws Throwable
     */
    public function iniciar(Empresa $empresa, Plano $plano): Pagamento
    {
        try {
            if ($existente = $this->referenciaPendenteDoMesmoPlano($empresa, $plano)) {
                Log::info('Referência de pagamento pendente reutilizada', [
                    'empresa_id' => $empresa->id,
                    'pagamento_id' => $existente->id,
                ]);

                return $existente;
            }

            return DB::transaction(function () use ($empresa, $plano) {
                $subscricao = Subscricao::create([
                    'empresa_id' => $empresa->id,
                    'plano_id' => $plano->id,
                    'estado' => 'pendente',
                    'renovacao_automatica' => true,
                ]);

                $pagamento = $this->criarPagamentoComReferencia($subscricao, $plano);

                if (! $empresa->temAcesso()) {
                    $this->tenantService->marcarPendente($empresa);
                }

                Log::info('Subscrição iniciada, aguarda pagamento', [
                    'empresa_id' => $empresa->id,
                    'plano_id' => $plano->id,
                    'pagamento_id' => $pagamento->id,
                    'referencia_externa' => $pagamento->referencia_externa,
                ]);

                return $this->tenantManager->semTenant(fn () => $pagamento->fresh(['subscricao.plano']));
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
     * Gera a referência de pagamento do PRÓXIMO período de uma subscrição
     * ativa e avisa os utilizadores da empresa. Chamado pela rotina diária
     * quando faltam poucos dias para o fim e a renovação está ativa.
     *
     * Com referências de pagamento não há débito automático: "renovação
     * automática" significa que o sistema trata de gerar e enviar a
     * referência a tempo; quem paga é sempre a empresa.
     */
    public function gerarRenovacao(Subscricao $subscricao): Pagamento
    {
        return $this->tenantManager->semTenant(function () use ($subscricao) {
            return DB::transaction(function () use ($subscricao) {
                $subscricao->loadMissing('plano', 'empresa');

                $pagamento = $this->criarPagamentoComReferencia($subscricao, $subscricao->plano);

                $subscricao->empresa->utilizadores()->where('ativo', true)->get()
                    ->each(fn (User $utilizador) => $utilizador->notify(new RenovacaoProximaNotification(
                        $subscricao->termina_em->format('d/m/Y'),
                        (string) $pagamento->referencia_externa,
                        (string) $pagamento->valor,
                        $pagamento->moeda,
                        $pagamento->expira_em->format('d/m/Y'),
                    )));

                Log::info('Referência de renovação gerada', [
                    'subscricao_id' => $subscricao->id,
                    'pagamento_id' => $pagamento->id,
                ]);

                return $pagamento;
            });
        });
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

    public function retomarRenovacao(Subscricao $subscricao): Subscricao
    {
        $subscricao->update([
            'renovacao_automatica' => true,
            'cancelada_em' => null,
        ]);

        Log::info('Renovação automática retomada', ['subscricao_id' => $subscricao->id]);

        return $subscricao->fresh();
    }

    protected function criarPagamentoComReferencia(Subscricao $subscricao, Plano $plano): Pagamento
    {
        $pagamento = Pagamento::create([
            'empresa_id' => $subscricao->empresa_id,
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

        return $pagamento;
    }

    protected function referenciaPendenteDoMesmoPlano(Empresa $empresa, Plano $plano): ?Pagamento
    {
        $pendentes = $this->tenantManager->semTenant(fn () => Pagamento::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'pendente')
            ->where('expira_em', '>', now())
            ->with('subscricao.plano')
            ->latest('id')
            ->get());

        return $pendentes->first(fn (Pagamento $p) => $p->subscricao?->plano_id === $plano->id);
    }
}
