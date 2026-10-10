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
     * o MESMO plano, devolve-a em vez de gerar outra.
     *
     * Duas fases, de propósito: (1) transação curta só com escritas na BD;
     * (2) chamada HTTP ao gateway FORA da transação (não segura uma ligação
     * à BD até 10 s). Se o gateway falhar, o pagamento fica 'expirado' e a
     * subscrição 'cancelada' — nunca um "pendente" sem referência.
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

            [$subscricao, $pagamento] = DB::transaction(function () use ($empresa, $plano) {
                $subscricao = Subscricao::create([
                    'empresa_id' => $empresa->id,
                    'plano_id' => $plano->id,
                    'estado' => 'pendente',
                    'renovacao_automatica' => true,
                ]);

                $pagamento = $this->criarPagamento($subscricao, $plano);

                if (! $empresa->temAcesso()) {
                    $this->tenantService->marcarPendente($empresa);
                }

                return [$subscricao, $pagamento];
            });

            try {
                $pagamento = $this->pedirReferenciaAoGateway($pagamento);
            } catch (Throwable $e) {
                $subscricao->update(['estado' => 'cancelada', 'cancelada_em' => now()]);

                throw $e;
            }

            Log::info('Subscrição iniciada, aguarda pagamento', [
                'empresa_id' => $empresa->id,
                'plano_id' => $plano->id,
                'pagamento_id' => $pagamento->id,
                'referencia_externa' => $pagamento->referencia_externa,
            ]);

            return $this->tenantManager->semTenant(fn () => $pagamento->fresh(['subscricao.plano']));
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
     * ativa e avisa os utilizadores da empresa (e-mails depois de tudo
     * gravado; uma falha de e-mail não invalida a referência).
     */
    public function gerarRenovacao(Subscricao $subscricao): Pagamento
    {
        return $this->tenantManager->semTenant(function () use ($subscricao) {
            $subscricao->loadMissing('plano', 'empresa');

            $pagamento = DB::transaction(fn () => $this->criarPagamento($subscricao, $subscricao->plano));
            $pagamento = $this->pedirReferenciaAoGateway($pagamento);

            $subscricao->empresa->utilizadores()->where('ativo', true)->get()
                ->each(function (User $utilizador) use ($subscricao, $pagamento) {
                    try {
                        $utilizador->notify(new RenovacaoProximaNotification(
                            $subscricao->termina_em->format('d/m/Y'),
                            (string) $pagamento->referencia_externa,
                            (string) $pagamento->valor,
                            $pagamento->moeda,
                            $pagamento->expira_em->format('d/m/Y'),
                        ));
                    } catch (Throwable $e) {
                        Log::warning('Referência de renovação gerada, mas o aviso falhou', [
                            'utilizador_id' => $utilizador->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                });

            Log::info('Referência de renovação gerada', [
                'subscricao_id' => $subscricao->id,
                'pagamento_id' => $pagamento->id,
            ]);

            return $pagamento;
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

    protected function criarPagamento(Subscricao $subscricao, Plano $plano): Pagamento
    {
        return Pagamento::create([
            'empresa_id' => $subscricao->empresa_id,
            'subscricao_id' => $subscricao->id,
            'gateway' => $this->gateway->identificador(),
            'valor' => $plano->preco,
            'moeda' => $plano->moeda,
            'estado' => 'pendente',
            'expira_em' => now()->addDays((int) Config::get('subscricoes.validade_referencia_dias', 3)),
        ]);
    }

    /**
     * Chamada ao gateway, fora de qualquer transação. Sem referência, o
     * pagamento é inútil: marca-o 'expirado' para nunca ser reutilizado por
     * referenciaPendenteDoMesmoPlano().
     */
    protected function pedirReferenciaAoGateway(Pagamento $pagamento): Pagamento
    {
        try {
            $resultado = $this->gateway->gerarReferencia($pagamento);

            $pagamento->update([
                'referencia_externa' => $resultado['referencia_externa'],
                'payload_bruto' => $resultado['payload'],
            ]);

            return $pagamento;
        } catch (Throwable $e) {
            $pagamento->update(['estado' => 'expirado']);

            throw $e;
        }
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
