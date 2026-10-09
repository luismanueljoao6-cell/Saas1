<?php

namespace Modules\Subscricoes\Services;

use Illuminate\Support\Facades\Cache;
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
     * Fluxo em 3 passos, sem HTTP dentro de transações:
     *  1. (transação curta) cria Subscricao + Pagamento 'pendente';
     *  2. (fora de transação) pede a referência ao gateway;
     *  3. grava a referência. Se o gateway falhar, os registos do passo 1
     *     são encerrados (pagamento 'expirado', subscrição 'cancelada').
     *
     * Um lock por empresa serializa cliques repetidos em "Subscrever".
     *
     * @throws Throwable
     */
    public function iniciar(Empresa $empresa, Plano $plano): Pagamento
    {
        try {
            return Cache::lock("subscricoes:iniciar:{$empresa->id}", 30)->block(10, function () use ($empresa, $plano) {
                if ($existente = $this->referenciaPendenteDoMesmoPlano($empresa, $plano)) {
                    Log::info('Referência de pagamento pendente reutilizada', [
                        'empresa_id' => $empresa->id,
                        'pagamento_id' => $existente->id,
                    ]);

                    return $existente;
                }

                $pagamento = DB::transaction(function () use ($empresa, $plano) {
                    $subscricao = Subscricao::create([
                        'empresa_id' => $empresa->id,
                        'plano_id' => $plano->id,
                        'estado' => 'pendente',
                        'renovacao_automatica' => true,
                    ]);

                    return $this->criarPagamentoPendente($subscricao, $plano);
                });

                $pagamento = $this->obterReferencia($pagamento, cancelarSubscricaoSeFalhar: true);

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
     * Gera a referência do PRÓXIMO período. A referência vale até ao fim do
     * período de tolerância (termina_em + core.periodo_tolerancia_dias), para
     * não expirar no mesmo instante em que a subscrição termina. Idempotente:
     * se já existe uma referência pendente válida, devolve-a sem notificar.
     */
    public function gerarRenovacao(Subscricao $subscricao): Pagamento
    {
        return $this->tenantManager->semTenant(function () use ($subscricao) {
            return Cache::lock("subscricoes:renovacao:{$subscricao->id}", 60)->block(10, function () use ($subscricao) {
                $subscricao->loadMissing('plano', 'empresa');

                $existente = Pagamento::query()
                    ->where('subscricao_id', $subscricao->id)
                    ->where('estado', 'pendente')
                    ->where('expira_em', '>', now())
                    ->latest('id')
                    ->first();

                if ($existente) {
                    return $existente;
                }

                $graca = (int) Config::get('core.periodo_tolerancia_dias', 5);
                $expiraEm = $subscricao->termina_em->copy()->addDays($graca);

                $pagamento = DB::transaction(
                    fn () => $this->criarPagamentoPendente($subscricao, $subscricao->plano, $expiraEm)
                );

                $pagamento = $this->obterReferencia($pagamento, cancelarSubscricaoSeFalhar: false);

                $this->notificarRenovacao($subscricao, $pagamento);

                Log::info('Referência de renovação gerada', [
                    'subscricao_id' => $subscricao->id,
                    'pagamento_id' => $pagamento->id,
                ]);

                return $pagamento;
            });
        });
    }

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

    protected function criarPagamentoPendente(Subscricao $subscricao, Plano $plano, ?\DateTimeInterface $expiraEm = null): Pagamento
    {
        return Pagamento::create([
            'empresa_id' => $subscricao->empresa_id,
            'subscricao_id' => $subscricao->id,
            'gateway' => $this->gateway->identificador(),
            'valor' => $plano->preco,
            'moeda' => $plano->moeda,
            'estado' => 'pendente',
            'expira_em' => $expiraEm ?? now()->addDays((int) Config::get('subscricoes.validade_referencia_dias', 3)),
        ]);
    }

    /**
     * Pede a referência ao gateway FORA de qualquer transação e grava-a.
     */
    protected function obterReferencia(Pagamento $pagamento, bool $cancelarSubscricaoSeFalhar): Pagamento
    {
        try {
            $resultado = $this->gateway->gerarReferencia($pagamento);
        } catch (Throwable $e) {
            $this->encerrarPagamentoSemReferencia($pagamento, $cancelarSubscricaoSeFalhar);

            throw $e;
        }

        try {
            $pagamento->update([
                'referencia_externa' => $resultado['referencia_externa'],
                'payload_bruto' => $resultado['payload'],
            ]);
        } catch (Throwable $e) {
            // A referência existe no gateway mas não ficou gravada: o webhook
            // ainda a consegue ligar pelos custom_fields (ver PagamentoService).
            Log::critical('Referência criada no gateway mas NÃO gravada localmente.', [
                'pagamento_id' => $pagamento->id,
                'referencia_externa' => $resultado['referencia_externa'] ?? null,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $pagamento;
    }

    protected function encerrarPagamentoSemReferencia(Pagamento $pagamento, bool $cancelarSubscricao): void
    {
        try {
            $this->tenantManager->semTenant(function () use ($pagamento, $cancelarSubscricao) {
                DB::transaction(function () use ($pagamento, $cancelarSubscricao) {
                    Pagamento::query()->whereKey($pagamento->id)->update([
                        'estado' => 'expirado',
                        'expira_em' => now(),
                    ]);

                    if ($cancelarSubscricao) {
                        Subscricao::query()->whereKey($pagamento->subscricao_id)->update([
                            'estado' => 'cancelada',
                            'cancelada_em' => now(),
                        ]);
                    }
                });
            });
        } catch (Throwable $e) {
            Log::error('Não foi possível encerrar o pagamento sem referência.', [
                'pagamento_id' => $pagamento->id,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    protected function notificarRenovacao(Subscricao $subscricao, Pagamento $pagamento): void
    {
        $subscricao->empresa->utilizadores()->where('ativo', true)->get()->each(
            function (User $utilizador) use ($subscricao, $pagamento) {
                try {
                    $utilizador->notify(new RenovacaoProximaNotification(
                        $subscricao->termina_em->format('d/m/Y'),
                        (string) $pagamento->referencia_externa,
                        (string) $pagamento->valor,
                        $pagamento->moeda,
                        $pagamento->expira_em->format('d/m/Y'),
                    ));
                } catch (Throwable $e) {
                    Log::error('Falha ao notificar renovação (a referência mantém-se).', [
                        'pagamento_id' => $pagamento->id,
                        'utilizador_id' => $utilizador->id,
                        'erro' => $e->getMessage(),
                    ]);
                }
            }
        );
    }

    /**
     * Só considera referências JÁ geradas (com referencia_externa).
     */
    protected function referenciaPendenteDoMesmoPlano(Empresa $empresa, Plano $plano): ?Pagamento
    {
        $pendentes = $this->tenantManager->semTenant(fn () => Pagamento::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'pendente')
            ->whereNotNull('referencia_externa')
            ->where('expira_em', '>', now())
            ->with('subscricao.plano')
            ->latest('id')
            ->get());

        return $pendentes->first(fn (Pagamento $p) => $p->subscricao?->plano_id === $plano->id);
    }
}
