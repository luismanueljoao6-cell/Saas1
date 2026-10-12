<?php

namespace Modules\Subscricoes\Services;

use DomainException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantService;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Notifications\RenovacaoProximaNotification;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use RuntimeException;
use Throwable;

class SubscricaoService
{
    public function __construct(
        protected GatewayPagamentoInterface $gateway,
        protected TenantService $tenantService,
        protected TenantManager $tenantManager,
    ) {}

    /**
     * Cria uma subscrição e uma intenção de pagamento de forma serializada
     * por empresa. A chamada HTTP ao gateway fica fora da transação.
     *
     * Se já existir uma referência válida para o mesmo plano, reutiliza-a.
     * Se outro pedido estiver a criar a referência, não cria uma segunda
     * cobrança: pede ao cliente para repetir o pedido após a primeira operação.
     *
     * @throws Throwable
     */
    public function iniciar(Empresa $empresa, Plano $plano): Pagamento
    {
        try {
            [$subscricao, $pagamento, $reutilizado] = $this->tenantManager->semTenant(
                fn () => DB::transaction(function () use ($empresa, $plano) {
                    // Serializa pedidos simultâneos de subscrição da mesma empresa.
                    $empresaBloqueada = Empresa::query()->lockForUpdate()->findOrFail($empresa->id);

                    $existente = Pagamento::query()
                        ->where('empresa_id', $empresaBloqueada->id)
                        ->where('estado', 'pendente')
                        ->where('expira_em', '>', now())
                        ->whereHas('subscricao', fn ($query) => $query->where('estado', 'pendente'))
                        ->with('subscricao')
                        ->latest('id')
                        ->first();

                    if ($existente) {
                        if ((int) $existente->subscricao->plano_id !== (int) $plano->id) {
                            throw new DomainException(
                                'Já existe um pagamento pendente para outro plano. Conclua ou deixe expirar esse pagamento antes de mudar de plano.'
                            );
                        }

                        return [null, $existente, true];
                    }

                    $subscricao = Subscricao::create([
                        'empresa_id' => $empresaBloqueada->id,
                        'plano_id' => $plano->id,
                        'estado' => 'pendente',
                        'renovacao_automatica' => true,
                    ]);

                    $pagamento = $this->criarPagamento($subscricao, $plano);

                    return [$subscricao, $pagamento, false];
                })
            );

            if ($reutilizado) {
                if (! $pagamento->referencia_externa) {
                    throw new RuntimeException(
                        'Já existe um pedido de pagamento em preparação para este plano. Tente novamente dentro de instantes.'
                    );
                }

                Log::info('Referência de pagamento pendente reutilizada', [
                    'empresa_id' => $empresa->id,
                    'pagamento_id' => $pagamento->id,
                ]);

                return $this->tenantManager->semTenant(
                    fn () => $pagamento->fresh(['subscricao.plano'])
                );
            }

            try {
                $pagamento = $this->pedirReferenciaAoGateway($pagamento);

                // Só bloquear a empresa depois de o gateway ter criado a
                // referência. Se a integração falhar, o estado anterior mantém-se.
                $this->tenantManager->semTenant(function () use ($empresa) {
                    $empresaAtual = Empresa::query()->findOrFail($empresa->id);

                    if (! $empresaAtual->temAcesso()) {
                        $this->tenantService->marcarPendente($empresaAtual);
                    }
                });
            } catch (Throwable $e) {
                $this->tenantManager->semTenant(function () use ($subscricao) {
                    $subscricao->update(['estado' => 'cancelada', 'cancelada_em' => now()]);
                });

                throw $e;
            }

            Log::info('Subscrição iniciada, aguarda pagamento', [
                'empresa_id' => $empresa->id,
                'plano_id' => $plano->id,
                'pagamento_id' => $pagamento->id,
                'referencia_externa' => $pagamento->referencia_externa,
            ]);

            return $this->tenantManager->semTenant(
                fn () => $pagamento->fresh(['subscricao.plano'])
            );
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
     * Gera uma referência para o próximo período. A operação é idempotente:
     * se já houver uma referência pendente para esta subscrição, reutiliza-a.
     */
    public function gerarRenovacao(Subscricao $subscricao): Pagamento
    {
        return $this->tenantManager->semTenant(function () use ($subscricao) {
            [$subscricaoBloqueada, $pagamento, $reutilizado] = DB::transaction(function () use ($subscricao) {
                $subscricaoBloqueada = Subscricao::query()
                    ->lockForUpdate()
                    ->with('plano', 'empresa')
                    ->findOrFail($subscricao->id);

                $existente = Pagamento::query()
                    ->where('subscricao_id', $subscricaoBloqueada->id)
                    ->where('estado', 'pendente')
                    ->where('expira_em', '>', now())
                    ->latest('id')
                    ->first();

                if ($existente) {
                    return [$subscricaoBloqueada, $existente, true];
                }

                return [
                    $subscricaoBloqueada,
                    $this->criarPagamento($subscricaoBloqueada, $subscricaoBloqueada->plano),
                    false,
                ];
            });

            if ($reutilizado && ! $pagamento->referencia_externa) {
                throw new RuntimeException(
                    'Já existe uma referência de renovação em preparação. Tente novamente dentro de instantes.'
                );
            }

            if (! $reutilizado) {
                try {
                    $pagamento = $this->pedirReferenciaAoGateway($pagamento);
                } catch (Throwable $e) {
                    throw $e;
                }
            }

            if (! $reutilizado) {
                $subscricaoBloqueada->loadMissing('plano', 'empresa');

                $subscricaoBloqueada->empresa->utilizadores()->where('ativo', true)->get()
                    ->each(function (User $utilizador) use ($subscricaoBloqueada, $pagamento) {
                        try {
                            $utilizador->notify(new RenovacaoProximaNotification(
                                $subscricaoBloqueada->termina_em->format('d/m/Y'),
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
            }

            Log::info($reutilizado ? 'Referência de renovação reutilizada' : 'Referência de renovação gerada', [
                'subscricao_id' => $subscricaoBloqueada->id,
                'pagamento_id' => $pagamento->id,
            ]);

            return $pagamento->fresh(['subscricao.plano']);
        });
    }

    /**
     * Cancela a renovação automática; o acesso mantém-se até termina_em.
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

    protected function pedirReferenciaAoGateway(Pagamento $pagamento): Pagamento
    {
        try {
            $resultado = $this->gateway->gerarReferencia($pagamento);

            if (empty($resultado['referencia_externa'])) {
                throw GatewayPagamentoException::falhaAoGerarReferencia(
                    $this->gateway->identificador(),
                    'o gateway não devolveu uma referência externa'
                );
            }

            $pagamento->update([
                'referencia_externa' => (string) $resultado['referencia_externa'],
                'payload_bruto' => $resultado['payload'] ?? [],
            ]);

            return $pagamento->fresh();
        } catch (Throwable $e) {
            $pagamento->update(['estado' => 'expirado']);

            throw $e;
        }
    }
}
