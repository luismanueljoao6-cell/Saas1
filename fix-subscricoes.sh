#!/usr/bin/env bash
# =============================================================================
# fix-subscricoes.sh — correções da auditoria do módulo Subscricoes (Saas1)
#
# Uso (na raiz do repositório, no terminal do Codespace):
#     bash fix-subscricoes.sh                # aplica, migra, testa e faz commit local
#     bash fix-subscricoes.sh --sem-migrar   # não corre php artisan migrate
#     bash fix-subscricoes.sh --sem-testes   # não corre os testes
#     bash fix-subscricoes.sh --sem-commit   # deixa as alterações por commitar
#
# É idempotente: pode ser corrido mais do que uma vez. Não faz push.
# =============================================================================
set -euo pipefail

MIGRAR=1; TESTAR=1; COMMITAR=1
for arg in "$@"; do
  case "$arg" in
    --sem-migrar) MIGRAR=0 ;;
    --sem-testes) TESTAR=0 ;;
    --sem-commit) COMMITAR=0 ;;
    *) echo "Opção desconhecida: $arg"; exit 2 ;;
  esac
done

cd "$(git rev-parse --show-toplevel)"
M="Modules/Subscricoes"
[ -d "$M/app" ] || { echo "ERRO: $M não encontrado. Corre este script na raiz do Saas1."; exit 1; }
command -v python3 >/dev/null || { echo "ERRO: python3 não encontrado."; exit 1; }

echo "==> A criar ficheiros novos"
mkdir -p "$M/app/Console" "$M/app/Models" "$M/database/migrations" "$M/tests/Feature" "$M/resources/views/subscricao"

# -----------------------------------------------------------------------------
# MIGRAÇÕES
# -----------------------------------------------------------------------------
cat > "$M/database/migrations/2026_10_17_000001_create_webhook_eventos_table.php" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Caixa de entrada durável: todo o webhook autenticado é guardado AQUI
        // antes de ser confirmado ao gateway, independentemente de a fila ou
        // da aplicação falharem a seguir.
        Schema::create('webhook_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 40);
            $table->char('chave_evento', 64)->unique()->comment('sha256(gateway + corpo bruto): deduplica reenvios');
            $table->string('referencia_externa')->nullable()->index();
            $table->string('valor', 20)->nullable();
            $table->longText('corpo_bruto');
            $table->string('estado', 20)->default('recebido')
                ->comment('recebido|processado|rejeitado|ilegivel|falhado|resolvido');
            $table->text('motivo_falha')->nullable();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->unsignedInteger('recebimentos')->default(1);
            $table->timestamp('processado_em')->nullable();
            $table->timestamps();

            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_eventos');
    }
};
PHP

cat > "$M/database/migrations/2026_10_17_000002_add_cancelamento_remoto_to_pagamentos_table.php" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagamentos', function (Blueprint $table) {
            // Referência expirada/cancelada localmente mas ainda viva na ProxyPay:
            // a reconciliação repete o cancelamento até ter sucesso.
            $table->boolean('cancelamento_remoto_pendente')->default(false)->index();
            $table->unsignedSmallInteger('cancelamento_remoto_tentativas')->default(0);
            $table->text('cancelamento_remoto_erro')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $table) {
            $table->dropIndex(['cancelamento_remoto_pendente']);
            $table->dropColumn([
                'cancelamento_remoto_pendente',
                'cancelamento_remoto_tentativas',
                'cancelamento_remoto_erro',
            ]);
        });
    }
};
PHP

cat > "$M/database/migrations/2026_10_17_000003_add_preco_to_subscricoes_table.php" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscricoes', function (Blueprint $table) {
            // Preço e moeda contratados: completam o snapshot de termos.
            $table->decimal('preco', 12, 2)->nullable();
            $table->string('moeda', 3)->nullable();
        });

        DB::table('subscricoes')
            ->select(['id', 'plano_id'])
            ->orderBy('id')
            ->chunkById(100, function ($subscricoes): void {
                foreach ($subscricoes as $subscricao) {
                    // Melhor evidência do preço contratado: o primeiro pagamento
                    // emitido; na falta dele, o preço atual do plano.
                    $origem = DB::table('pagamentos')
                        ->where('subscricao_id', $subscricao->id)
                        ->orderBy('id')
                        ->first(['valor', 'moeda']);

                    $valor = $origem?->valor;
                    $moeda = $origem?->moeda;

                    if ($valor === null) {
                        $plano = DB::table('planos')
                            ->where('id', $subscricao->plano_id)
                            ->first(['preco', 'moeda']);

                        if (! $plano) {
                            continue;
                        }

                        $valor = $plano->preco;
                        $moeda = $plano->moeda;
                    }

                    DB::table('subscricoes')
                        ->where('id', $subscricao->id)
                        ->update(['preco' => $valor, 'moeda' => $moeda]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('subscricoes', function (Blueprint $table) {
            $table->dropColumn(['preco', 'moeda']);
        });
    }
};
PHP

# -----------------------------------------------------------------------------
# MODELO
# -----------------------------------------------------------------------------
cat > "$M/app/Models/WebhookEvento.php" <<'PHP'
<?php

namespace Modules\Subscricoes\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Caixa de entrada de webhooks. Deliberadamente SEM BelongsToTenant: o evento
 * chega do gateway antes de existir qualquer tenant; é uma tabela de sistema.
 */
class WebhookEvento extends Model
{
    public const RECEBIDO = 'recebido';

    public const PROCESSADO = 'processado';

    public const REJEITADO = 'rejeitado';

    public const ILEGIVEL = 'ilegivel';

    public const FALHADO = 'falhado';

    public const RESOLVIDO = 'resolvido';

    protected $table = 'webhook_eventos';

    protected $fillable = [
        'gateway',
        'chave_evento',
        'referencia_externa',
        'valor',
        'corpo_bruto',
        'estado',
        'motivo_falha',
        'tentativas',
        'recebimentos',
        'processado_em',
    ];

    protected function casts(): array
    {
        return [
            'tentativas' => 'integer',
            'recebimentos' => 'integer',
            'processado_em' => 'datetime',
        ];
    }
}
PHP

# -----------------------------------------------------------------------------
# CONTROLLER DO WEBHOOK (caixa de entrada durável)
# -----------------------------------------------------------------------------
cat > "$M/app/Http/Controllers/Webhooks/ProxyPayWebhookController.php" <<'PHP'
<?php

namespace Modules\Subscricoes\Http\Controllers\Webhooks;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Jobs\ProcessarPagamentoConfirmadoJob;
use Modules\Subscricoes\Models\WebhookEvento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Throwable;

/**
 * A autenticidade do pedido já foi confirmada pelo middleware
 * VerificarAssinaturaWebhook.
 *
 * Política de resposta (a ProxyPay reenvia webhooks e exige tratamento
 * idempotente):
 *  - Só se responde 2xx depois de o evento estar GUARDADO na tabela
 *    webhook_eventos. Se a gravação falhar, responde-se 503 e o gateway
 *    reenvia — nunca se confirma um evento que não ficou recuperável.
 *  - Payload autenticado mas ilegível: fica guardado como 'ilegivel' para
 *    revisão (comando subscricoes:reconciliar) e responde-se 200, porque
 *    reenviar o mesmo corpo não o tornaria legível.
 *  - Falha ao despachar o job (fila em baixo): o evento fica 'recebido' e a
 *    reconciliação reprocessa-o; responde-se 200 porque já é recuperável.
 *  - Reenvio de um evento já tratado: 200 sem reprocessar.
 *
 * É obrigatório ter `php artisan queue:work` e o scheduler ativos.
 */
class ProxyPayWebhookController extends Controller
{
    public function __construct(protected GatewayPagamentoInterface $gateway) {}

    public function __invoke(Request $request): JsonResponse
    {
        $gateway = $this->gateway->identificador();
        $corpo = (string) $request->getContent();

        try {
            [$evento, $novo] = $this->guardarEvento($gateway, $corpo);
        } catch (Throwable $e) {
            Log::critical('Webhook autenticado NÃO guardado — a pedir reenvio ao gateway', [
                'gateway' => $gateway,
                'erro' => $e->getMessage(),
            ]);

            return response()->json(['recebido' => false], 503);
        }

        if (
            ! $novo
            && in_array($evento->estado, [WebhookEvento::PROCESSADO, WebhookEvento::REJEITADO, WebhookEvento::ILEGIVEL, WebhookEvento::RESOLVIDO], true)
        ) {
            return response()->json(['recebido' => true, 'duplicado' => true]);
        }

        try {
            $notificacao = $this->gateway->interpretarNotificacao($request);
        } catch (Throwable $e) {
            $evento->update([
                'estado' => WebhookEvento::ILEGIVEL,
                'motivo_falha' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Log::critical('Webhook autenticado mas não interpretável — guardado para revisão manual', [
                'gateway' => $gateway,
                'evento_id' => $evento->id,
                'erro' => $e->getMessage(),
            ]);

            return response()->json(['recebido' => true]);
        }

        $evento->update([
            'referencia_externa' => $notificacao['referencia_externa'],
            'valor' => $notificacao['valor'] ?? null,
        ]);

        try {
            ProcessarPagamentoConfirmadoJob::dispatch(
                $gateway,
                $notificacao['referencia_externa'],
                $notificacao['payload'],
                $notificacao['valor'] ?? null,
                $evento->id,
            );
        } catch (Throwable $e) {
            Log::critical('Webhook guardado, mas o processamento falhou/não foi despachado — a reconciliação reprocessa', [
                'gateway' => $gateway,
                'evento_id' => $evento->id,
                'referencia_externa' => $notificacao['referencia_externa'],
                'erro' => $e->getMessage(),
            ]);
        }

        return response()->json(['recebido' => true]);
    }

    /**
     * @return array{0: WebhookEvento, 1: bool} [evento, é novo]
     */
    protected function guardarEvento(string $gateway, string $corpo): array
    {
        $chave = hash('sha256', $gateway."\n".$corpo);

        try {
            $evento = WebhookEvento::query()->create([
                'gateway' => $gateway,
                'chave_evento' => $chave,
                'corpo_bruto' => $corpo,
                'estado' => WebhookEvento::RECEBIDO,
            ]);

            return [$evento, true];
        } catch (UniqueConstraintViolationException) {
            $evento = WebhookEvento::query()->where('chave_evento', $chave)->firstOrFail();
            $evento->increment('recebimentos');

            return [$evento, false];
        }
    }
}
PHP

# -----------------------------------------------------------------------------
# COMANDOS: reconciliação e saúde
# -----------------------------------------------------------------------------
cat > "$M/app/Console/ReconciliarPagamentosCommand.php" <<'PHP'
<?php

namespace Modules\Subscricoes\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\TenantManager;
use Modules\Subscricoes\Jobs\ProcessarPagamentoConfirmadoJob;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\WebhookEvento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Throwable;

/**
 * Reconciliação financeira. Corre de 10 em 10 minutos (scheduler) e pode ser
 * corrida à mão:
 *
 *   php artisan subscricoes:reconciliar
 *   php artisan subscricoes:reconciliar --resolver=12 --resolver=13
 *
 * Faz três coisas: reprocessa webhooks guardados e não processados; repete
 * cancelamentos remotos que falharam; e lista anomalias que exigem um humano.
 * Devolve código de saída 1 se houver anomalias (útil para monitorização).
 */
class ReconciliarPagamentosCommand extends Command
{
    protected $signature = 'subscricoes:reconciliar
        {--minutos=5 : Idade mínima (min.) de um evento pendente antes de ser reprocessado}
        {--max-tentativas=10 : Tentativas máximas por evento}
        {--resolver=* : IDs de eventos já tratados manualmente (passam a "resolvido")}';

    protected $description = 'Reprocessa webhooks pendentes, repete cancelamentos remotos e lista anomalias financeiras';

    public function handle(TenantManager $tenantManager, GatewayPagamentoInterface $gateway): int
    {
        return $tenantManager->semTenant(function () use ($gateway): int {
            $resolvidos = $this->resolverManuais();
            $reprocessados = $this->reprocessarEventos($gateway);
            $cancelados = $this->repetirCancelamentosRemotos($gateway);
            $anomalias = $this->detetarAnomalias();

            $this->info("Eventos resolvidos manualmente: {$resolvidos}");
            $this->info("Eventos reprocessados: {$reprocessados}");
            $this->info("Cancelamentos remotos repetidos com sucesso: {$cancelados}");

            if ($anomalias === []) {
                $this->info('Sem anomalias.');

                return self::SUCCESS;
            }

            $this->table(['Tipo', 'Detalhe'], $anomalias);

            Log::critical('Reconciliação de subscrições encontrou anomalias', [
                'total' => count($anomalias),
                'anomalias' => $anomalias,
            ]);

            return self::FAILURE;
        });
    }

    protected function resolverManuais(): int
    {
        $ids = array_filter(array_map('intval', (array) $this->option('resolver')));

        if ($ids === []) {
            return 0;
        }

        return WebhookEvento::query()->whereIn('id', $ids)->update([
            'estado' => WebhookEvento::RESOLVIDO,
            'processado_em' => now(),
        ]);
    }

    protected function reprocessarEventos(GatewayPagamentoInterface $gateway): int
    {
        $limite = now()->subMinutes((int) $this->option('minutos'));
        $max = (int) $this->option('max-tentativas');
        $total = 0;

        WebhookEvento::query()
            ->where('gateway', $gateway->identificador())
            ->whereIn('estado', [WebhookEvento::RECEBIDO, WebhookEvento::FALHADO])
            ->where('tentativas', '<', $max)
            ->where('updated_at', '<', $limite)
            ->chunkById(100, function ($eventos) use ($gateway, &$total) {
                foreach ($eventos as $evento) {
                    try {
                        if ($evento->referencia_externa) {
                            $notificacao = [
                                'referencia_externa' => $evento->referencia_externa,
                                'valor' => $evento->valor,
                                'payload' => json_decode($evento->corpo_bruto, true) ?: [],
                            ];
                        } else {
                            $pedido = Request::create('/', 'POST', [], [], [], [
                                'CONTENT_TYPE' => 'application/json',
                            ], $evento->corpo_bruto);

                            $notificacao = $gateway->interpretarNotificacao($pedido);

                            $evento->update([
                                'referencia_externa' => $notificacao['referencia_externa'],
                                'valor' => $notificacao['valor'] ?? null,
                            ]);
                        }
                    } catch (Throwable $e) {
                        $evento->update([
                            'estado' => WebhookEvento::ILEGIVEL,
                            'motivo_falha' => mb_substr($e->getMessage(), 0, 1000),
                        ]);

                        continue;
                    }

                    try {
                        $evento->increment('tentativas');

                        ProcessarPagamentoConfirmadoJob::dispatch(
                            $evento->gateway,
                            $notificacao['referencia_externa'],
                            $notificacao['payload'],
                            $notificacao['valor'] ?? null,
                            $evento->id,
                        );

                        $total++;
                    } catch (Throwable $e) {
                        Log::error('Reconciliação: falha ao reprocessar webhook', [
                            'evento_id' => $evento->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $total;
    }

    protected function repetirCancelamentosRemotos(GatewayPagamentoInterface $gateway): int
    {
        if (! method_exists($gateway, 'cancelarReferencia')) {
            return 0;
        }

        $sucessos = 0;

        Pagamento::query()
            ->where('cancelamento_remoto_pendente', true)
            ->where('estado', '!=', 'confirmado')
            ->whereNotNull('referencia_externa')
            ->chunkById(50, function ($pagamentos) use ($gateway, &$sucessos) {
                foreach ($pagamentos as $pagamento) {
                    try {
                        $gateway->cancelarReferencia($pagamento);

                        $pagamento->update([
                            'cancelamento_remoto_pendente' => false,
                            'cancelamento_remoto_erro' => null,
                        ]);

                        $sucessos++;
                    } catch (Throwable $e) {
                        $pagamento->update([
                            'cancelamento_remoto_tentativas' => $pagamento->cancelamento_remoto_tentativas + 1,
                            'cancelamento_remoto_erro' => mb_substr($e->getMessage(), 0, 500),
                        ]);
                    }
                }
            });

        return $sucessos;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    protected function detetarAnomalias(): array
    {
        $max = (int) $this->option('max-tentativas');
        $linhas = [];

        WebhookEvento::query()
            ->where(function ($q) use ($max) {
                $q->whereIn('estado', [WebhookEvento::REJEITADO, WebhookEvento::ILEGIVEL])
                    ->orWhere(fn ($q2) => $q2->where('estado', WebhookEvento::FALHADO)->where('tentativas', '>=', $max));
            })
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (WebhookEvento $e) use (&$linhas) {
                $linhas[] = [
                    "evento {$e->estado}",
                    "#{$e->id} ref={$e->referencia_externa} valor={$e->valor} — {$e->motivo_falha}",
                ];
            });

        Pagamento::query()
            ->where('estado', 'pendente')
            ->whereNull('referencia_externa')
            ->where('created_at', '<', now()->subMinutes(15))
            ->limit(200)
            ->get()
            ->each(function (Pagamento $p) use (&$linhas) {
                $linhas[] = ['pagamento sem referência', "#{$p->id} — criação remota possivelmente incompleta; verificar na ProxyPay"];
            });

        Pagamento::query()
            ->where('estado', 'confirmado')
            ->whereHas('subscricao', fn ($q) => $q->where('estado', 'pendente'))
            ->limit(200)
            ->get()
            ->each(function (Pagamento $p) use (&$linhas) {
                $linhas[] = ['pago mas não ativado', "pagamento #{$p->id} confirmado com subscrição ainda pendente"];
            });

        Pagamento::query()
            ->where('cancelamento_remoto_pendente', true)
            ->limit(200)
            ->get()
            ->each(function (Pagamento $p) use (&$linhas) {
                $linhas[] = ['referência não cancelada', "pagamento #{$p->id} ref={$p->referencia_externa} — {$p->cancelamento_remoto_erro}"];
            });

        return $linhas;
    }
}
PHP

cat > "$M/app/Console/SaudeSubscricoesCommand.php" <<'PHP'
<?php

namespace Modules\Subscricoes\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Subscricoes\Models\WebhookEvento;

/**
 * Verifica as dependências operacionais do módulo: scheduler, fila e caixa
 * de entrada de webhooks. Corre de hora a hora; código de saída 1 = problema.
 *
 *   php artisan subscricoes:saude
 */
class SaudeSubscricoesCommand extends Command
{
    protected $signature = 'subscricoes:saude';

    protected $description = 'Verifica scheduler, fila e webhooks pendentes do módulo de subscrições';

    public function handle(): int
    {
        $problemas = [];

        $ultima = Cache::get('subscricoes:ultima_verificacao');

        if (! is_array($ultima) || empty($ultima['em'])) {
            $problemas[] = 'A rotina diária de subscrições ainda não correu (scheduler parado?).';
        } else {
            if (Carbon::parse($ultima['em'])->lt(now()->subHours(26))) {
                $problemas[] = 'A rotina diária não corre há mais de 26 horas (última: '.$ultima['em'].').';
            }

            if (($ultima['falhas'] ?? 0) > 0) {
                $problemas[] = "A última rotina diária terminou com {$ultima['falhas']} falha(s) — ver logs.";
            }
        }

        $pendentes = WebhookEvento::query()
            ->whereIn('estado', [WebhookEvento::RECEBIDO, WebhookEvento::FALHADO])
            ->where('created_at', '<', now()->subMinutes(15))
            ->count();

        if ($pendentes > 0) {
            $problemas[] = "{$pendentes} webhook(s) guardado(s) e por processar há mais de 15 min (fila parada?).";
        }

        $revisao = WebhookEvento::query()
            ->whereIn('estado', [WebhookEvento::REJEITADO, WebhookEvento::ILEGIVEL])
            ->count();

        if ($revisao > 0) {
            $problemas[] = "{$revisao} webhook(s) a aguardar revisão manual (php artisan subscricoes:reconciliar).";
        }

        if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
            $atrasados = DB::table('jobs')
                ->where('available_at', '<', now()->subMinutes(10)->getTimestamp())
                ->count();

            if ($atrasados > 0) {
                $problemas[] = "{$atrasados} job(s) na fila há mais de 10 min — o queue:work está ativo?";
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $falhados = DB::table('failed_jobs')->where('failed_at', '>', now()->subDay())->count();

            if ($falhados > 0) {
                $problemas[] = "{$falhados} job(s) falhado(s) nas últimas 24 h (php artisan queue:failed).";
            }
        }

        if ($problemas === []) {
            $this->info('Subscrições: tudo operacional.');

            return self::SUCCESS;
        }

        foreach ($problemas as $problema) {
            $this->error($problema);
        }

        Log::critical('Saúde do módulo Subscrições: problemas detetados', ['problemas' => $problemas]);

        return self::FAILURE;
    }
}
PHP

# -----------------------------------------------------------------------------
# VIEW: página de pagamento completa (entidade, referência, montante, validade)
# -----------------------------------------------------------------------------
cat > "$M/resources/views/subscricao/pendente.blade.php" <<'BLADE'
@extends('core::layouts.app')

@section('titulo', 'Pagamento pendente')

@section('conteudo')
    @php
        $entidade = $pagamento->entidade();
        $referencia = (string) $pagamento->referencia_externa;
        $rotulos = [
            'pendente' => 'A aguardar pagamento',
            'confirmado' => 'Pagamento confirmado',
            'expirado' => 'Referência expirada',
            'falhado' => 'Pagamento falhado',
        ];
        $montante = number_format((float) $pagamento->valor, 2, ',', ' ').' '.$pagamento->moeda;
    @endphp

    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm" x-data="{ copiado: null }">
        <h1 class="text-xl font-semibold text-slate-900">
            {{ $pagamento->estado === 'confirmado' ? 'Pagamento confirmado' : 'Falta só o pagamento' }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Plano <strong>{{ $pagamento->subscricao->plano->nome }}</strong>
        </p>

        @if (! $entidade && $pagamento->estado === 'pendente')
            <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
                Não foi possível obter a entidade Multicaixa para este pagamento.
                Contacte o suporte antes de pagar.
            </div>
        @endif

        <dl class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-slate-50 text-left">
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Entidade</dt>
                <dd class="font-mono text-xl font-semibold text-slate-900"
                    @click="navigator.clipboard.writeText('{{ $entidade }}'); copiado = 'entidade'">
                    {{ $entidade ?? '—' }}
                    <span x-show="copiado === 'entidade'" class="ml-2 text-xs font-normal text-emerald-600">copiado</span>
                </dd>
            </div>
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Referência</dt>
                <dd class="font-mono text-xl font-semibold text-slate-900"
                    @click="navigator.clipboard.writeText('{{ $referencia }}'); copiado = 'referencia'">
                    {{ trim(chunk_split($referencia, 3, ' ')) }}
                    <span x-show="copiado === 'referencia'" class="ml-2 text-xs font-normal text-emerald-600">copiado</span>
                </dd>
            </div>
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Montante exato</dt>
                <dd class="text-lg font-semibold text-slate-900">{{ $montante }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Válida até</dt>
                <dd class="text-sm text-slate-900">{{ $pagamento->expira_em?->format('d/m/Y H:i') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Estado</dt>
                <dd class="text-sm font-medium text-slate-900">{{ $rotulos[$pagamento->estado] ?? $pagamento->estado }}</dd>
            </div>
        </dl>

        <p class="mt-2 text-xs text-slate-500">Toca num valor para o copiar. Paga o montante exato indicado.</p>

        @if ($pagamento->estado === 'pendente')
            <p class="mt-6 text-sm text-slate-500">
                Assim que o banco confirmar o pagamento, a subscrição fica ativa automaticamente.
                Se já pagaste, atualiza esta página dentro de instantes.
            </p>
        @elseif ($pagamento->estado === 'expirado' || $pagamento->estado === 'falhado')
            <p class="mt-6 text-sm text-slate-500">
                Esta referência já não pode ser usada.
                <a href="{{ route('subscricoes.planos') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Gerar uma nova</a>.
                Se já pagaste esta referência, contacta o suporte — o pagamento será reconciliado.
            </p>
        @endif

        <a href="{{ route('core.painel') }}" class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">
            &larr; Voltar ao painel
        </a>
    </div>
@endsection
BLADE

# -----------------------------------------------------------------------------
# TESTES DE REGRESSÃO
# -----------------------------------------------------------------------------
cat > "$M/tests/Feature/WebhookInboxTest.php" <<'PHP'
<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Models\WebhookEvento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\Gateways\ProxyPayGateway;
use Modules\Subscricoes\Services\SubscricaoService;
use Tests\TestCase;

class GatewayRenovacaoFalso implements GatewayPagamentoInterface
{
    public function identificador(): string
    {
        return 'proxypay';
    }

    public function gerarReferencia(Pagamento $pagamento): array
    {
        return ['referencia_externa' => '9'.str_pad((string) $pagamento->id, 8, '0', STR_PAD_LEFT), 'payload' => ['entity_id' => '12345']];
    }

    public function validarPedidoWebhook(Request $request): bool
    {
        return true;
    }

    public function interpretarNotificacao(Request $request): array
    {
        return ['referencia_externa' => 'x', 'estado' => 'confirmado', 'payload' => []];
    }
}

class WebhookInboxTest extends TestCase
{
    use RefreshDatabase;

    private const SEGREDO = 'chave-de-teste';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscricoes.gateway' => 'proxypay',
            'subscricoes.gateways.proxypay.api_key' => self::SEGREDO,
            'subscricoes.gateways.proxypay.entity_id' => '12345',
        ]);
    }

    protected function pagamentoPendente(string $nif, string $referencia): Pagamento
    {
        return app(TenantManager::class)->semTenant(function () use ($nif, $referencia) {
            $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
            $plano = Plano::create(['nome' => 'Plano '.$nif, 'slug' => 'plano-'.$nif, 'preco' => 9900, 'periodo_dias' => 30]);

            $subscricao = Subscricao::create([
                'empresa_id' => $empresa->id,
                'plano_id' => $plano->id,
                'periodo_dias' => 30,
                'preco' => 9900,
                'moeda' => 'AOA',
                'limites' => [],
                'servicos' => [],
                'estado' => 'pendente',
                'renovacao_automatica' => true,
            ]);

            return Pagamento::create([
                'empresa_id' => $empresa->id,
                'subscricao_id' => $subscricao->id,
                'gateway' => 'proxypay',
                'referencia_externa' => $referencia,
                'valor' => '9900.00',
                'moeda' => 'AOA',
                'estado' => 'pendente',
                'payload_bruto' => ['entity_id' => '12345'],
                'expira_em' => now()->addDays(3),
            ]);
        });
    }

    protected function enviar(string $corpo, ?string $assinatura = null)
    {
        $assinatura ??= hash_hmac('sha256', $corpo, self::SEGREDO);

        return $this->call('POST', '/api/webhooks/proxypay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $assinatura,
        ], $corpo);
    }

    public function test_webhook_valido_e_guardado_antes_de_ser_processado(): void
    {
        $pagamento = $this->pagamentoPendente('820000001', '123456789');

        $this->enviar('{"reference_id":"123456789","amount":"9900.00"}')->assertOk();

        $evento = WebhookEvento::query()->firstOrFail();
        $this->assertSame('123456789', $evento->referencia_externa);
        $this->assertSame(WebhookEvento::PROCESSADO, $evento->estado);
        $this->assertSame('confirmado', Pagamento::withoutGlobalScopes()->find($pagamento->id)->estado);
    }

    public function test_webhook_duplicado_nao_duplica_evento_nem_estende_a_subscricao(): void
    {
        $pagamento = $this->pagamentoPendente('820000002', '123456790');
        $corpo = '{"reference_id":"123456790","amount":"9900.00"}';

        $this->enviar($corpo)->assertOk();
        $terminaEm = Subscricao::withoutGlobalScopes()->find($pagamento->subscricao_id)->termina_em;

        $this->enviar($corpo)->assertOk();

        $this->assertSame(1, WebhookEvento::query()->count());
        $this->assertSame(2, WebhookEvento::query()->first()->recebimentos);
        $this->assertEquals(
            $terminaEm,
            Subscricao::withoutGlobalScopes()->find($pagamento->subscricao_id)->termina_em
        );
    }

    public function test_webhook_autenticado_mas_ilegivel_fica_guardado_para_revisao(): void
    {
        $this->enviar('{"sem_referencia":true}')->assertOk();

        $evento = WebhookEvento::query()->firstOrFail();
        $this->assertSame(WebhookEvento::ILEGIVEL, $evento->estado);
        $this->assertNotEmpty($evento->motivo_falha);
        $this->assertStringContainsString('sem_referencia', $evento->corpo_bruto);
    }

    public function test_webhook_com_assinatura_invalida_e_rejeitado_e_nao_e_guardado(): void
    {
        $this->enviar('{"reference_id":"1"}', str_repeat('0', 64))->assertStatus(401);

        $this->assertSame(0, WebhookEvento::query()->count());
    }

    public function test_reconciliacao_reprocessa_evento_guardado_e_nao_processado(): void
    {
        $pagamento = $this->pagamentoPendente('820000003', '123456791');

        $evento = WebhookEvento::query()->create([
            'gateway' => 'proxypay',
            'chave_evento' => hash('sha256', 'teste-reconciliacao'),
            'corpo_bruto' => '{"reference_id":"123456791","amount":"9900.00"}',
            'estado' => WebhookEvento::RECEBIDO,
        ]);
        WebhookEvento::query()->whereKey($evento->id)->update(['updated_at' => now()->subHour()]);

        Artisan::call('subscricoes:reconciliar');

        $this->assertSame('confirmado', Pagamento::withoutGlobalScopes()->find($pagamento->id)->estado);
        $this->assertSame(WebhookEvento::PROCESSADO, $evento->fresh()->estado);
    }

    public function test_gateway_proxypay_exige_entidade_configurada_antes_de_cobrar(): void
    {
        config(['subscricoes.gateways.proxypay.entity_id' => null]);
        $pagamento = $this->pagamentoPendente('820000004', '123456792');

        $this->expectException(GatewayPagamentoException::class);

        (new ProxyPayGateway)->gerarReferencia($pagamento);
    }

    public function test_pagamento_expoe_a_entidade_multicaixa(): void
    {
        $pagamento = $this->pagamentoPendente('820000005', '123456793');

        $this->assertSame('12345', $pagamento->entidade());
    }

    public function test_renovacao_mantem_o_preco_contratado_mesmo_que_o_plano_mude(): void
    {
        $this->app->singleton(GatewayPagamentoInterface::class, fn () => new GatewayRenovacaoFalso);
        $pagamento = $this->pagamentoPendente('820000006', '123456794');

        $subscricao = Subscricao::withoutGlobalScopes()->find($pagamento->subscricao_id);
        $subscricao->update(['estado' => 'ativa', 'inicio_em' => now()->subDays(27), 'termina_em' => now()->addDays(3)]);
        Pagamento::withoutGlobalScopes()->whereKey($pagamento->id)->update(['estado' => 'confirmado']);
        Plano::query()->whereKey($subscricao->plano_id)->update(['preco' => 15000]);

        $renovacao = app(SubscricaoService::class)->gerarRenovacao($subscricao->fresh());

        $this->assertSame('9900.00', (string) $renovacao->valor);
    }
}
PHP

# -----------------------------------------------------------------------------
# PATCHES EM FICHEIROS EXISTENTES (idempotentes; falham de forma explícita)
# -----------------------------------------------------------------------------
echo "==> A aplicar patches"
python3 - <<'PY'
import re, sys

M = "Modules/Subscricoes"
erros = []

def ler(p):
    with open(p, encoding="utf-8") as f:
        return f.read()

def gravar(p, t):
    with open(p, "w", encoding="utf-8") as f:
        f.write(t)

def patch(caminho, antigo, novo, marcador):
    """Substitui 'antigo' por 'novo' uma única vez; ignora se 'marcador' já existir."""
    t = ler(caminho)
    if marcador in t:
        print(f"  = {caminho}: já aplicado ({marcador[:40]!r})")
        return
    if t.count(antigo) < 1:
        erros.append(f"{caminho}: âncora não encontrada -> {antigo[:70]!r}")
        return
    gravar(caminho, t.replace(antigo, novo, 1))
    print(f"  + {caminho}: {marcador[:50]}")

def patch_re(caminho, padrao, subst, marcador, flags=0):
    t = ler(caminho)
    if marcador in t:
        print(f"  = {caminho}: já aplicado ({marcador[:40]!r})")
        return
    novo, n = re.subn(padrao, subst, t, flags=flags)
    if n == 0:
        erros.append(f"{caminho}: padrão não encontrado -> {padrao[:70]!r}")
        return
    gravar(caminho, novo)
    print(f"  + {caminho}: {marcador[:50]} ({n}x)")

# --- Job de processamento: atualizar o estado do evento na caixa de entrada ---
job = f"{M}/app/Jobs/ProcessarPagamentoConfirmadoJob.php"
patch(job,
      "use Modules\\Subscricoes\\Services\\PagamentoService;",
      "use Modules\\Subscricoes\\Models\\WebhookEvento;\nuse Modules\\Subscricoes\\Services\\PagamentoService;",
      "use Modules\\Subscricoes\\Models\\WebhookEvento;")
patch(job,
      "        protected ?string $valorRecebido = null,\n    ) {}",
      "        protected ?string $valorRecebido = null,\n        protected ?int $webhookEventoId = null,\n    ) {}",
      "protected ?int $webhookEventoId")
patch(job,
      "$pagamentoService->confirmar($pagamento, $this->payloadBruto, null, $this->valorRecebido);",
      "$pagamentoService->confirmar($pagamento, $this->payloadBruto, null, $this->valorRecebido);\n\n            $this->atualizarEvento(WebhookEvento::PROCESSADO);",
      "$this->atualizarEvento(WebhookEvento::PROCESSADO)")
patch(job,
      "            $this->fail($e);",
      "            $this->atualizarEvento(WebhookEvento::REJEITADO, $e->getMessage());\n\n            $this->fail($e);",
      "$this->atualizarEvento(WebhookEvento::REJEITADO")
patch(job,
      "            throw $e; // permite ao Laravel gerir novas tentativas / backoff",
      "            $this->atualizarEvento(WebhookEvento::FALHADO, $e->getMessage());\n\n            throw $e; // permite ao Laravel gerir novas tentativas / backoff",
      "$this->atualizarEvento(WebhookEvento::FALHADO")
t = ler(job)
if "function atualizarEvento" not in t:
    novo_metodos = '''
    /**
     * Estado final de um job esgotado. Só toca em eventos ainda "em aberto":
     * um evento já marcado como processado/rejeitado não deve ser sobrescrito.
     */
    public function failed(?Throwable $e): void
    {
        if ($this->webhookEventoId === null) {
            return;
        }

        try {
            WebhookEvento::query()
                ->whereKey($this->webhookEventoId)
                ->whereIn('estado', [WebhookEvento::RECEBIDO, WebhookEvento::FALHADO])
                ->update([
                    'estado' => WebhookEvento::FALHADO,
                    'motivo_falha' => mb_substr((string) $e?->getMessage(), 0, 1000),
                ]);
        } catch (Throwable $inner) {
            Log::warning('Não foi possível atualizar o evento de webhook falhado', ['erro' => $inner->getMessage()]);
        }
    }

    protected function atualizarEvento(string $estado, ?string $motivo = null): void
    {
        if ($this->webhookEventoId === null) {
            return;
        }

        try {
            $dados = [
                'estado' => $estado,
                'motivo_falha' => $motivo !== null ? mb_substr($motivo, 0, 1000) : null,
            ];

            if (in_array($estado, [WebhookEvento::PROCESSADO, WebhookEvento::REJEITADO], true)) {
                $dados['processado_em'] = now();
            }

            WebhookEvento::query()->whereKey($this->webhookEventoId)->increment('tentativas', 1, $dados);
        } catch (Throwable $e) {
            Log::warning('Não foi possível atualizar o evento de webhook', ['erro' => $e->getMessage()]);
        }
    }
}
'''
    t = t.rstrip()
    assert t.endswith("}")
    gravar(job, t[:-1].rstrip() + "\n" + novo_metodos)
    print(f"  + {job}: failed() e atualizarEvento()")

# --- Gateway: entidade obrigatória e proteção sandbox/produção ---
gw = f"{M}/app/Services/Gateways/ProxyPayGateway.php"
patch(gw,
      "    public function gerarReferencia(Pagamento $pagamento): array\n    {\n        $this->garantirCredenciaisConfiguradas();",
      "    public function gerarReferencia(Pagamento $pagamento): array\n    {\n        $this->garantirConfiguracaoDeCobranca();",
      "$this->garantirConfiguracaoDeCobranca();")
patch(gw,
      "    protected function garantirCredenciaisConfiguradas(): void",
      '''    /**
     * Antes de emitir uma cobrança o cliente tem de receber todos os dados
     * para pagar: sem entidade Multicaixa a referência é inútil. Impede
     * também misturar sandbox e produção.
     */
    protected function garantirConfiguracaoDeCobranca(): void
    {
        $this->garantirCredenciaisConfiguradas();

        if (! $this->entityId || ! preg_match('/^\\d{3,10}$/', (string) $this->entityId)) {
            throw GatewayPagamentoException::falhaAoGerarReferencia(
                $this->identificador(),
                'PROXYPAY_ENTITY_ID não está configurada (ou é inválida)'
            );
        }

        $emSandbox = str_contains($this->baseUrl, 'sandbox');

        if (app()->environment('production') && $emSandbox) {
            throw GatewayPagamentoException::falhaAoGerarReferencia(
                $this->identificador(),
                'PROXYPAY_BASE_URL aponta para a sandbox em ambiente de produção'
            );
        }

        if (! app()->environment('production') && ! $emSandbox && ! app()->runningUnitTests()) {
            Log::warning('ProxyPay: a usar a URL de PRODUÇÃO fora do ambiente de produção — cobranças reais possíveis.', [
                'base_url' => $this->baseUrl,
                'ambiente' => app()->environment(),
            ]);
        }
    }

    protected function garantirCredenciaisConfiguradas(): void''',
      "function garantirConfiguracaoDeCobranca")

# --- Pagamento: entidade(), campos de cancelamento remoto ---
pg = f"{M}/app/Models/Pagamento.php"
patch(pg,
      "        'confirmado_por',\n    ];",
      "        'confirmado_por',\n        'cancelamento_remoto_pendente',\n        'cancelamento_remoto_tentativas',\n        'cancelamento_remoto_erro',\n    ];",
      "'cancelamento_remoto_pendente',\n        'cancelamento_remoto_tentativas'")
patch(pg,
      "            'confirmado_em' => 'datetime',\n        ];",
      "            'confirmado_em' => 'datetime',\n            'cancelamento_remoto_pendente' => 'boolean',\n        ];",
      "'cancelamento_remoto_pendente' => 'boolean'")
patch(pg,
      "    public function estaExpirado(): bool",
      '''    /**
     * Entidade Multicaixa a apresentar ao cliente: a devolvida/registada na
     * criação da referência; na falta dela, a configurada para o gateway.
     */
    public function entidade(): ?string
    {
        $entidade = $this->payload_bruto['entity_id'] ?? config("subscricoes.gateways.{$this->gateway}.entity_id");

        return $entidade !== null && $entidade !== '' ? (string) $entidade : null;
    }

    public function estaExpirado(): bool''',
      "public function entidade(): ?string")

# --- Subscricao: preço contratado ---
sb = f"{M}/app/Models/Subscricao.php"
patch(sb,
      "        'periodo_dias',\n        'limites',",
      "        'periodo_dias',\n        'preco',\n        'moeda',\n        'limites',",
      "'periodo_dias',\n        'preco',")
patch(sb,
      "            'periodo_dias' => 'integer',",
      "            'periodo_dias' => 'integer',\n            'preco' => 'decimal:2',",
      "'preco' => 'decimal:2'")

# --- SubscricaoService: preço contratado, falhas de cancelamento, referência órfã ---
sv = f"{M}/app/Services/SubscricaoService.php"
patch(sv,
      "                        'periodo_dias' => $plano->periodo_dias,\n",
      "                        'periodo_dias' => $plano->periodo_dias,\n                        'preco' => $plano->preco,\n                        'moeda' => $plano->moeda,\n",
      "'preco' => $plano->preco,\n                        'moeda' => $plano->moeda,")
patch(sv,
      "            'valor' => $plano->preco,\n            'moeda' => $plano->moeda,",
      "            // Política de renovação (config subscricoes.renovacao_preco):\n            // 'contratado' (omissão) mantém o preço da compra; 'atual' usa o do plano.\n            'valor' => $this->precoAplicavel($subscricao, $plano),\n            'moeda' => $subscricao->moeda ?? $plano->moeda,",
      "$this->precoAplicavel($subscricao, $plano)")
patch(sv,
      "    protected function garantirEstadoPendenteSeNecessario(Empresa $empresa): void",
      '''    protected function precoAplicavel(Subscricao $subscricao, Plano $plano): mixed
    {
        if (Config::get('subscricoes.renovacao_preco', 'contratado') === 'atual') {
            return $plano->preco;
        }

        return $subscricao->preco ?? $plano->preco;
    }

    /**
     * Marca a referência para o cancelamento remoto ser repetido pela
     * reconciliação (php artisan subscricoes:reconciliar).
     */
    protected function registarFalhaCancelamentoRemoto(Pagamento $pagamento, Throwable $e): void
    {
        try {
            $this->tenantManager->semTenant(fn () => Pagamento::query()
                ->whereKey($pagamento->id)
                ->update([
                    'cancelamento_remoto_pendente' => true,
                    'cancelamento_remoto_erro' => mb_substr($e->getMessage(), 0, 500),
                ]));
        } catch (Throwable $inner) {
            Log::error('Não foi possível marcar o cancelamento remoto como pendente', [
                'pagamento_id' => $pagamento->id,
                'erro' => $inner->getMessage(),
            ]);
        }
    }

    protected function garantirEstadoPendenteSeNecessario(Empresa $empresa): void''',
      "function registarFalhaCancelamentoRemoto")
patch_re(sv,
         r"^(\s*)Log::critical\('Referência localmente invalidada, mas o cancelamento remoto falhou'",
         r"\1$this->registarFalhaCancelamentoRemoto($pagamento, $e);\n\1Log::critical('Referência localmente invalidada, mas o cancelamento remoto falhou'",
         "registarFalhaCancelamentoRemoto($pagamento, $e)", re.M)
patch_re(sv,
         r"^(\s*)Log::critical\('Pagamento expirado localmente, mas a referência externa não foi cancelada'",
         r"\1$this->registarFalhaCancelamentoRemoto($pendente, $e);\n\1Log::critical('Pagamento expirado localmente, mas a referência externa não foi cancelada'",
         "registarFalhaCancelamentoRemoto($pendente, $e)", re.M)
patch(sv,
      "        try {\n            $resultado = $this->gateway->gerarReferencia($pagamento);",
      "        $resultado = null;\n\n        try {\n            $resultado = $this->gateway->gerarReferencia($pagamento);",
      "$resultado = null;")
patch(sv,
      "        } catch (Throwable $e) {\n            $pagamento->update(['estado' => 'expirado']);",
      "        } catch (Throwable $e) {\n            if (is_array($resultado) && ! empty($resultado['referencia_externa'])) {\n                // O gateway criou a referência mas a gravação local falhou:\n                // referência órfã — só a reconciliação manual a recupera.\n                Log::critical('Referência criada no gateway mas não gravada localmente', [\n                    'pagamento_id' => $pagamento->id,\n                    'referencia_externa' => $resultado['referencia_externa'],\n                    'erro' => $e->getMessage(),\n                ]);\n            }\n\n            $pagamento->update(['estado' => 'expirado']);",
      "Referência criada no gateway mas não gravada localmente")

# --- Job diário: heartbeat e contagem de falhas ---
vj = f"{M}/app/Jobs/VerificarSubscricoesExpiradasJob.php"
patch(vj,
      "use Illuminate\\Support\\Facades\\Config;",
      "use Illuminate\\Support\\Facades\\Cache;\nuse Illuminate\\Support\\Facades\\Config;",
      "use Illuminate\\Support\\Facades\\Cache;")
patch(vj,
      "    /**\n     * Injeção via handle()",
      "    /** Falhas isoladas nesta execução (cada uma é apanhada para não parar as restantes). */\n    protected int $falhas = 0;\n\n    /**\n     * Injeção via handle()",
      "protected int $falhas = 0;")
patch(vj,
      "            $this->gerarRenovacoes();\n        });\n    }",
      "            $this->gerarRenovacoes();\n        });\n\n        // Heartbeat lido por `php artisan subscricoes:saude`.\n        Cache::forever('subscricoes:ultima_verificacao', [\n            'em' => now()->toIso8601String(),\n            'falhas' => $this->falhas,\n        ]);\n\n        if ($this->falhas > 0) {\n            Log::critical('Rotina diária de subscrições terminou com falhas isoladas', ['falhas' => $this->falhas]);\n        }\n    }",
      "Cache::forever('subscricoes:ultima_verificacao'")
patch_re(vj,
         r"(\n([ \t]+)\} catch \(Throwable \$e\) \{\n)",
         r"\1\2    $this->falhas++;\n",
         "$this->falhas++;")

# --- Provider: comandos e agendamento ---
pv = f"{M}/app/Providers/SubscricoesServiceProvider.php"
patch(pv,
      "use Modules\\Subscricoes\\Console\\VerificarSubscricoesCommand;",
      "use Modules\\Subscricoes\\Console\\ReconciliarPagamentosCommand;\nuse Modules\\Subscricoes\\Console\\SaudeSubscricoesCommand;\nuse Modules\\Subscricoes\\Console\\VerificarSubscricoesCommand;",
      "use Modules\\Subscricoes\\Console\\ReconciliarPagamentosCommand;")
patch(pv,
      "$this->commands([VerificarSubscricoesCommand::class]);",
      "$this->commands([\n                VerificarSubscricoesCommand::class,\n                ReconciliarPagamentosCommand::class,\n                SaudeSubscricoesCommand::class,\n            ]);",
      "ReconciliarPagamentosCommand::class,\n                SaudeSubscricoesCommand::class")
patch(pv,
      "                ->name('subscricoes:verificar-expiradas')\n                ->withoutOverlapping();",
      "                ->name('subscricoes:verificar-expiradas')\n                ->withoutOverlapping();\n\n            $schedule->command('subscricoes:reconciliar')\n                ->everyTenMinutes()\n                ->name('subscricoes:reconciliar')\n                ->withoutOverlapping();\n\n            $schedule->command('subscricoes:saude')\n                ->hourly()\n                ->name('subscricoes:saude')\n                ->withoutOverlapping();",
      "->name('subscricoes:reconciliar')")

# --- Config: política de preço de renovação ---
cf = f"{M}/config/config.php"
t = ler(cf)
if "renovacao_preco" not in t:
    t = t.rstrip()
    assert t.endswith("];")
    bloco = """
    /*
    |--------------------------------------------------------------------------
    | Preço aplicado nas renovações
    |--------------------------------------------------------------------------
    | 'contratado': a renovação mantém o preço da compra (snapshot na
    |               subscrição). Alterar o plano só afeta novas subscrições.
    | 'atual':      a renovação usa o preço atual do plano (exige comunicar a
    |               alteração ao cliente antes da renovação).
    */
    'renovacao_preco' => env('SUBSCRICOES_RENOVACAO_PRECO', 'contratado'),

];
"""
    gravar(cf, t[:-2].rstrip() + "\n" + bloco)
    print(f"  + {cf}: renovacao_preco")

# --- .env.example ---
env = ".env.example"
t = ler(env)
if "SUBSCRICOES_RENOVACAO_PRECO" not in t:
    gravar(env, t.rstrip() + "\nSUBSCRICOES_RENOVACAO_PRECO=contratado\n")
    print("  + .env.example: SUBSCRICOES_RENOVACAO_PRECO")

# --- README: secção de operação ---
rd = f"{M}/README.md"
t = ler(rd)
if "## Operação e reconciliação" not in t:
    gravar(rd, t.rstrip() + """

## Operação e reconciliação

- **Caixa de entrada de webhooks** (`webhook_eventos`): todo o webhook autenticado é guardado antes de ser confirmado ao gateway; reenvios idênticos são deduplicados.
- `php artisan subscricoes:reconciliar` — reprocessa webhooks pendentes, repete cancelamentos remotos falhados e lista anomalias (código de saída 1 se houver). Corre de 10 em 10 min no scheduler. Use `--resolver=ID` para marcar um evento tratado manualmente.
- `php artisan subscricoes:saude` — verifica scheduler, fila e webhooks por processar (de hora a hora).
- Requer `php artisan queue:work` e `php artisan schedule:run` (cron) ativos.
- Renovações: `SUBSCRICOES_RENOVACAO_PRECO=contratado|atual`.
""")
    print(f"  + {rd}: secção de operação")

if erros:
    print("\\nERROS:")
    for e in erros:
        print("  !", e)
    sys.exit(1)
PY

# -----------------------------------------------------------------------------
# VALIDAÇÃO
# -----------------------------------------------------------------------------
echo "==> A validar sintaxe PHP"
if command -v php >/dev/null; then
  FICHEIROS=$(git ls-files -m -o --exclude-standard -- "$M" | grep '\.php$' || true)
  for f in $FICHEIROS; do php -l "$f" >/dev/null || { echo "ERRO de sintaxe em $f"; exit 1; }; done
  echo "    OK ($(echo "$FICHEIROS" | wc -w) ficheiros)"
else
  echo "    php não encontrado — a saltar"
fi

if [ "$MIGRAR" = 1 ]; then
  echo "==> A correr migrações"
  php artisan migrate
fi

if [ "$TESTAR" = 1 ]; then
  echo "==> A correr testes do módulo"
  export XDEBUG_MODE=off
  php artisan test --filter=Subscricoes
fi

if [ "$COMMITAR" = 1 ]; then
  echo "==> A fazer commit (local)"
  git add "$M" .env.example
  git commit -m "fix(subscricoes): caixa de entrada de webhooks, reconciliação, página de pagamento com entidade, preço contratado e monitorização" || echo "    nada para commitar"
  echo "    Revê com 'git show --stat' e faz 'git push origin main' quando estiveres satisfeito."
fi

echo "==> Concluído."
