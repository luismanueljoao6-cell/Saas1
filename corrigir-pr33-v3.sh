#!/usr/bin/env bash
# =============================================================================
# corrigir-pr33-v3.sh — terceira ronda de correções da PR #33
#                       (branch feat/faturacao-fiscal-core, base 35a92c9)
#
#   1. CI: o 'composer audit' sai do job 'qualidade' e passa para o workflow
#      próprio "Auditoria de dependências" (audita o composer.lock sem instalar
#      nada e corre também todos os dias). Um aviso de segurança novo deixa de
#      fazer parecer que o Pint ou os testes falharam.
#   2. Atelier: uma campanha nunca fica presa em 'em_processamento'.
#      - Causa raiz: o envio só ficava ligado à Outbox DEPOIS de o job da
#        Outbox ser despachado; se o evento de estado chegasse antes, a
#        listener não encontrava o envio e perdia-se. Agora a Outbox e a
#        ligação gravam-se na mesma transação (o job só arranca no commit).
#      - Rede de segurança: CampanhaReconciliador + comando
#        'atelier:campanhas-reconciliar' (de 10 em 10 minutos) alinham os
#        envios com o estado real da Outbox e fecham a campanha. O job usa o
#        mesmo serviço. Nunca reenvia nada: só corrige o registo.
#
# Uso (na raiz do projeto, com a branch da PR em checkout):
#     bash corrigir-pr33-v3.sh           # aplica, valida e faz commits locais
#     bash corrigir-pr33-v3.sh --push    # idem + git push (sem --force)
#
# O próprio script pode estar dentro da pasta do projeto: nunca é adicionado
# ao git (usa "git apply --index", que só trata dos ficheiros do patch).
# Nunca toca na main, nunca faz merge, nunca faz force-push. É idempotente.
# =============================================================================
set -euo pipefail

RAMO_ESPERADO="feat/faturacao-fiscal-core"
COMMIT_BASE="35a92c9"
PUSH=0
[[ "${1:-}" == "--push" ]] && PUSH=1

erro() { echo "ERRO: $*" >&2; exit 1; }
info() { echo "==> $*"; }

# --- 0. Verificações de segurança --------------------------------------------
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || erro "corre isto na raiz do repositório Saas1."
[[ -f artisan ]] || erro "não encontrei o ficheiro 'artisan' — corre na raiz do projeto."

RAMO="$(git rev-parse --abbrev-ref HEAD)"
[[ "$RAMO" != "main" ]] || erro "estás na main. Este script nunca altera a main."
[[ "$RAMO" == "$RAMO_ESPERADO" ]] || erro "branch atual '$RAMO'; esperava '$RAMO_ESPERADO'. Faz: git checkout $RAMO_ESPERADO"
# Só interessam alterações em ficheiros já versionados (o próprio script pode estar por versionar).
{ git diff --quiet && git diff --cached --quiet; } \
  || erro "há alterações por gravar em ficheiros versionados. Faz commit ou stash antes de continuar."
git merge-base --is-ancestor "$COMMIT_BASE" HEAD 2>/dev/null \
  || erro "o commit $COMMIT_BASE não está no histórico desta branch. Faz: git pull --ff-only origin $RAMO_ESPERADO"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# --- 1. Patches ------------------------------------------------------------------
cat > "$TMP/01-ci.patch" <<'PATCH_V3_CI'
diff --git a/.github/workflows/auditoria-dependencias.yml b/.github/workflows/auditoria-dependencias.yml
new file mode 100644
index 0000000..6172fb0
--- /dev/null
+++ b/.github/workflows/auditoria-dependencias.yml
@@ -0,0 +1,35 @@
+name: Auditoria de dependências
+
+# Fica separada do CI de propósito: um aviso de segurança novo contra uma
+# dependência (que nada tem a ver com o código da PR) já não faz parecer que o
+# Pint ou os testes falharam, e a auditoria continua a correr todos os dias,
+# mesmo sem pushes, para apanhar avisos publicados depois do último commit.
+on:
+  push:
+    branches: [main]
+  pull_request:
+  schedule:
+    - cron: '17 6 * * *'
+  workflow_dispatch:
+
+concurrency:
+  group: auditoria-dependencias-${{ github.ref }}
+  cancel-in-progress: true
+
+permissions:
+  contents: read
+
+jobs:
+  composer-audit:
+    runs-on: ubuntu-latest
+    steps:
+      - uses: actions/checkout@v4
+
+      - uses: shivammathur/setup-php@v2
+        with:
+          php-version: '8.4'
+          extensions: mbstring, openssl, intl
+          coverage: none
+
+      # Audita o composer.lock tal como está: não instala nem atualiza nada.
+      - run: composer audit --locked
diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml
index ae4a36c..9713385 100644
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -73,7 +73,9 @@ jobs:
           for teste in "${testes[@]}"; do
             php artisan test Modules/Subscricoes/tests/Feature/AuditoriaSubscricoesTest.php --filter="$teste"
           done
-      - run: composer audit
+      # A auditoria de dependências (composer audit) corre no workflow próprio
+      # "Auditoria de dependências": um aviso de segurança novo não deve fazer
+      # parecer que o estilo ou os testes falharam.
 
   testes-mysql:
     runs-on: ubuntu-latest
PATCH_V3_CI

cat > "$TMP/02-atelier.patch" <<'PATCH_V3_ATELIER'
diff --git a/Modules/Atelier/app/Console/ReconciliarCampanhasCommand.php b/Modules/Atelier/app/Console/ReconciliarCampanhasCommand.php
new file mode 100644
index 0000000..58758e9
--- /dev/null
+++ b/Modules/Atelier/app/Console/ReconciliarCampanhasCommand.php
@@ -0,0 +1,24 @@
+<?php
+
+namespace Modules\Atelier\Console;
+
+use Illuminate\Console\Command;
+use Modules\Atelier\Services\CampanhaReconciliador;
+
+class ReconciliarCampanhasCommand extends Command
+{
+    protected $signature = 'atelier:campanhas-reconciliar {--limite=200 : Máximo de campanhas por execução}';
+
+    protected $description = 'Reconcilia campanhas paradas em processamento com o estado real da Outbox (rede de segurança dos eventos perdidos).';
+
+    public function handle(CampanhaReconciliador $reconciliador): int
+    {
+        $limite = max(1, min(1000, (int) $this->option('limite')));
+
+        $fechadas = $reconciliador->reconciliarEmProcessamento($limite);
+
+        $this->info("Campanhas concluídas pela reconciliação: {$fechadas}");
+
+        return self::SUCCESS;
+    }
+}
diff --git a/Modules/Atelier/app/Jobs/DispararCampanhaJob.php b/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
index 4deb1e3..fcac9a9 100644
--- a/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
+++ b/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
@@ -13,6 +13,7 @@
 use Modules\Atelier\Models\Campanha;
 use Modules\Atelier\Models\CampanhaEnvio;
 use Modules\Atelier\Models\CampanhaReenvio;
+use Modules\Atelier\Services\CampanhaReconciliador;
 use Modules\Atelier\Services\CrmService;
 use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
 use Modules\Atelier\Services\PortalLinkService;
@@ -196,48 +197,10 @@ protected function executar(
             return 'Empresa sem acesso ou sem o serviço atelier.';
         }
 
-        // Reconcilia estados terminais da Outbox mesmo quando o evento
-        // de sincronização se perdeu. A consulta é sempre limitada à empresa
-        // da campanha; não provoca reenvios automáticos.
-        $enviosComOutbox = $campanha->envios()
-            ->whereNotNull('notification_outbox_id')
-            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE])
-            ->get();
-
-        foreach ($enviosComOutbox as $envioOutbox) {
-            $outbox = NotificationOutbox::withoutGlobalScopes()
-                ->where('empresa_id', $campanha->empresa_id)
-                ->whereKey($envioOutbox->notification_outbox_id)
-                ->first();
-
-            if (! $outbox || ! in_array($outbox->status, [
-                NotificationOutbox::STATUS_FAILED,
-                NotificationOutbox::STATUS_DEAD_LETTER,
-            ], true)) {
-                continue;
-            }
-
-            $envioOutbox->update([
-                'estado' => CampanhaEnvio::ESTADO_FALHOU,
-                'erro' => $outbox->last_error ?: 'A notificação terminou sem sucesso na Outbox.',
-            ]);
-        }
-
-        $temPendenteReconhecido = $campanha->envios()
-            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
-            ->exists();
-
-        if ($campanha->estado === 'em_processamento' && ! $temPendenteReconhecido) {
-            $temFalhaReconhecida = $campanha->envios()
-                ->where('estado', CampanhaEnvio::ESTADO_FALHOU)
-                ->exists();
-
-            $campanha->update([
-                'estado' => $temFalhaReconhecida ? 'concluida_com_falhas' : 'enviada',
-                'enviada_em' => $campanha->enviada_em ?? now(),
-            ]);
-            $campanha->refresh();
-        }
+        // Reconcilia com o estado real da Outbox mesmo quando o evento de
+        // sincronização se perdeu. A consulta é sempre limitada à empresa da
+        // campanha; não provoca reenvios automáticos.
+        app(CampanhaReconciliador::class)->reconciliar($campanha);
 
         $concluida = in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true);
 
@@ -322,20 +285,27 @@ protected function executar(
 
             try {
                 if (in_array($campanha->canal, ['whatsapp', 'sms'], true)) {
-                    $outbox = $notificador->enfileirarViaOutbox(
-                        $cliente,
-                        $campanha->canal,
-                        $mensagem,
-                        'atelier-campanha-'.$campanha->id.'-cliente-'.$cliente->id.'-tentativa-'.$envio->tentativa,
-                    );
-
-                    $envio->update([
-                        'notification_outbox_id' => $outbox->id,
-                        'estado' => $outbox->status === NotificationOutbox::STATUS_ACCEPTED
-                            ? CampanhaEnvio::ESTADO_ACEITE
-                            : CampanhaEnvio::ESTADO_PENDENTE,
-                        'erro' => null,
-                    ]);
+                    // A Outbox e a ligação do envio a ela gravam-se na MESMA transação.
+                    // O job de processamento só arranca no commit (afterCommit), por isso
+                    // o evento de estado nunca chega antes de o envio apontar para a
+                    // Outbox — era assim que uma campanha podia ficar presa em
+                    // 'em_processamento'.
+                    DB::transaction(function () use ($notificador, $cliente, $campanha, $mensagem, $envio): void {
+                        $outbox = $notificador->enfileirarViaOutbox(
+                            $cliente,
+                            $campanha->canal,
+                            $mensagem,
+                            'atelier-campanha-'.$campanha->id.'-cliente-'.$cliente->id.'-tentativa-'.$envio->tentativa,
+                        );
+
+                        $envio->update([
+                            'notification_outbox_id' => $outbox->id,
+                            'estado' => $outbox->status === NotificationOutbox::STATUS_ACCEPTED
+                                ? CampanhaEnvio::ESTADO_ACEITE
+                                : CampanhaEnvio::ESTADO_PENDENTE,
+                            'erro' => null,
+                        ]);
+                    });
                 } else {
                     // O e-mail permanece síncrono até existir adaptador próprio na Outbox.
                     $notificador->notificarViaCanalUnico($cliente, $campanha->canal, $assunto, $mensagem);
diff --git a/Modules/Atelier/app/Providers/AtelierServiceProvider.php b/Modules/Atelier/app/Providers/AtelierServiceProvider.php
index 7c46bcb..7fc495b 100644
--- a/Modules/Atelier/app/Providers/AtelierServiceProvider.php
+++ b/Modules/Atelier/app/Providers/AtelierServiceProvider.php
@@ -7,6 +7,7 @@
 use Illuminate\Support\ServiceProvider;
 use Modules\Atelier\Console\EliminarDadosClienteCommand;
 use Modules\Atelier\Console\ExportarDadosClienteCommand;
+use Modules\Atelier\Console\ReconciliarCampanhasCommand;
 use Modules\Atelier\Events\PedidoMudouEstado;
 use Modules\Atelier\Jobs\EnviarCampanhasAgendadasJob;
 use Modules\Atelier\Jobs\LembretesProvaJob;
@@ -80,6 +81,7 @@ protected function registarComandos(): void
             $this->commands([
                 ExportarDadosClienteCommand::class,
                 EliminarDadosClienteCommand::class,
+                ReconciliarCampanhasCommand::class,
             ]);
         }
     }
@@ -109,6 +111,13 @@ protected function agendarTarefas(): void
                 ->hourly()
                 ->name('atelier:enviar-campanhas-agendadas')
                 ->withoutOverlapping();
+
+            // Rede de segurança: um evento da Outbox perdido deixaria uma
+            // campanha em 'em_processamento' para sempre (o botão recusa-a).
+            $schedule->command('atelier:campanhas-reconciliar')
+                ->everyTenMinutes()
+                ->name('atelier:campanhas-reconciliar')
+                ->withoutOverlapping();
         });
     }
 }
diff --git a/Modules/Atelier/app/Services/CampanhaReconciliador.php b/Modules/Atelier/app/Services/CampanhaReconciliador.php
new file mode 100644
index 0000000..de43851
--- /dev/null
+++ b/Modules/Atelier/app/Services/CampanhaReconciliador.php
@@ -0,0 +1,193 @@
+<?php
+
+namespace Modules\Atelier\Services;
+
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
+use Modules\Atelier\Models\Campanha;
+use Modules\Atelier\Models\CampanhaEnvio;
+use Modules\Core\Models\NotificationOutbox;
+use Throwable;
+
+/**
+ * Alinha os envios e o estado de uma campanha com o que a Outbox realmente fez.
+ *
+ * A listener SincronizarEstadoCampanhaOutbox faz isto em tempo real, mas um
+ * evento pode perder-se (worker morto a meio, falha da listener, ...) e uma
+ * campanha "em processamento" não tem outra forma de sair desse estado: o
+ * botão de envio recusa-a. Este serviço é a rede de segurança — idempotente,
+ * lê sempre o estado REAL da Outbox (limitado à empresa da campanha) e nunca
+ * envia nada: só corrige o registo.
+ *
+ * Ordem de locks: a listener bloqueia primeiro o envio e depois a campanha.
+ * Aqui os envios são atualizados SEM lock de campanha (updates condicionais e
+ * atómicos) e a campanha só é bloqueada no fim, a ler — nunca se pede um lock
+ * de envio com o da campanha tomado, por isso não há deadlock com a listener.
+ */
+class CampanhaReconciliador
+{
+    /** Um envio 'pendente' sem Outbox mais antigo do que isto foi abandonado (o job tem timeout de 15 min). */
+    public const ENVIO_ORFAO_APOS_MINUTOS = 30;
+
+    /** A Outbox aceitou a notificação (ou já foi mais longe). */
+    private const OUTBOX_ACEITE = [
+        NotificationOutbox::STATUS_ACCEPTED,
+        NotificationOutbox::STATUS_DELIVERED,
+        NotificationOutbox::STATUS_READ,
+    ];
+
+    /** A Outbox terminou sem sucesso: a notificação nunca vai ser entregue. */
+    private const OUTBOX_FALHADA = [
+        NotificationOutbox::STATUS_FAILED,
+        NotificationOutbox::STATUS_DEAD_LETTER,
+        NotificationOutbox::STATUS_CANCELLED,
+    ];
+
+    /**
+     * @param  bool  $abandonarOrfaos  Também marca como falhados os envios 'pendentes' que nunca
+     *                                 chegaram à Outbox e estão parados há demasiado tempo. Só deve
+     *                                 ser pedido por quem garante que nenhum job está a enviar.
+     */
+    public function reconciliar(Campanha $campanha, bool $abandonarOrfaos = false): void
+    {
+        $this->alinharComAOutbox($campanha);
+
+        if ($abandonarOrfaos) {
+            $this->abandonarEnviosOrfaos($campanha);
+        }
+
+        $this->fecharSeTerminou($campanha);
+
+        $campanha->refresh();
+    }
+
+    /**
+     * Reconcilia as campanhas paradas em 'em_processamento' (comando agendado).
+     *
+     * @return int quantas campanhas saíram de 'em_processamento'
+     */
+    public function reconciliarEmProcessamento(int $limite = 200): int
+    {
+        $fechadas = 0;
+
+        $campanhas = Campanha::withoutGlobalScopes()
+            ->where('estado', 'em_processamento')
+            ->orderBy('id')
+            ->limit($limite)
+            ->get();
+
+        foreach ($campanhas as $campanha) {
+            // Mesmo lock do DispararCampanhaJob: nunca reconciliar a meio de um envio.
+            $lock = Cache::lock('atelier:campanha:'.$campanha->id, 120);
+
+            if (! $lock->get()) {
+                continue;
+            }
+
+            try {
+                $this->reconciliar($campanha, abandonarOrfaos: true);
+
+                if ($campanha->estado !== 'em_processamento') {
+                    $fechadas++;
+                }
+            } catch (Throwable $e) {
+                // Uma campanha problemática (ou um deadlock) não pode impedir as restantes.
+                Log::warning('Atelier: falha ao reconciliar uma campanha', [
+                    'campanha_id' => $campanha->id,
+                    'erro' => $e->getMessage(),
+                ]);
+            } finally {
+                $lock->release();
+            }
+        }
+
+        return $fechadas;
+    }
+
+    protected function alinharComAOutbox(Campanha $campanha): void
+    {
+        $campanha->envios()
+            ->whereNotNull('notification_outbox_id')
+            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE])
+            ->chunkById(200, function ($envios) use ($campanha): void {
+                // A consulta é sempre limitada à empresa da campanha.
+                $outboxes = NotificationOutbox::withoutGlobalScopes()
+                    ->where('empresa_id', $campanha->empresa_id)
+                    ->whereIn('id', $envios->pluck('notification_outbox_id'))
+                    ->get()
+                    ->keyBy('id');
+
+                foreach ($envios as $envio) {
+                    $outbox = $outboxes->get($envio->notification_outbox_id);
+
+                    if (! $outbox) {
+                        continue;
+                    }
+
+                    // Updates condicionais: se a listener (ou outro reconciliador)
+                    // mexeu no envio entretanto, este update simplesmente não aplica.
+                    if (in_array($outbox->status, self::OUTBOX_FALHADA, true)) {
+                        CampanhaEnvio::query()
+                            ->whereKey($envio->id)
+                            ->where('notification_outbox_id', $outbox->id)
+                            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE])
+                            ->update([
+                                'estado' => CampanhaEnvio::ESTADO_FALHOU,
+                                'erro' => $outbox->last_error
+                                    ?: ($outbox->status === NotificationOutbox::STATUS_CANCELLED
+                                        ? 'A notificação foi cancelada.'
+                                        : 'A notificação terminou sem sucesso na Outbox.'),
+                            ]);
+                    } elseif (
+                        $envio->estado === CampanhaEnvio::ESTADO_PENDENTE
+                        && in_array($outbox->status, self::OUTBOX_ACEITE, true)
+                    ) {
+                        CampanhaEnvio::query()
+                            ->whereKey($envio->id)
+                            ->where('notification_outbox_id', $outbox->id)
+                            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
+                            ->update(['estado' => CampanhaEnvio::ESTADO_ACEITE, 'erro' => null]);
+                    }
+                }
+            });
+    }
+
+    protected function abandonarEnviosOrfaos(Campanha $campanha): void
+    {
+        $campanha->envios()
+            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
+            ->whereNull('notification_outbox_id')
+            ->where('updated_at', '<', now()->subMinutes(self::ENVIO_ORFAO_APOS_MINUTOS))
+            ->update([
+                'estado' => CampanhaEnvio::ESTADO_FALHOU,
+                'erro' => 'O envio foi interrompido antes de a notificação ser registada. Usa «Reenviar falhados».',
+            ]);
+    }
+
+    protected function fecharSeTerminou(Campanha $campanha): void
+    {
+        DB::transaction(function () use ($campanha): void {
+            $bloqueada = Campanha::withoutGlobalScopes()
+                ->whereKey($campanha->id)
+                ->where('empresa_id', $campanha->empresa_id)
+                ->lockForUpdate()
+                ->first();
+
+            if (! $bloqueada || $bloqueada->estado !== 'em_processamento') {
+                return;
+            }
+
+            if ($bloqueada->envios()->where('estado', CampanhaEnvio::ESTADO_PENDENTE)->exists()) {
+                return;
+            }
+
+            $temFalha = $bloqueada->envios()->where('estado', CampanhaEnvio::ESTADO_FALHOU)->exists();
+
+            $bloqueada->update([
+                'estado' => $temFalha ? 'concluida_com_falhas' : 'enviada',
+                'enviada_em' => $bloqueada->enviada_em ?? now(),
+            ]);
+        });
+    }
+}
diff --git a/Modules/Atelier/tests/Feature/ReconciliacaoCampanhasTest.php b/Modules/Atelier/tests/Feature/ReconciliacaoCampanhasTest.php
new file mode 100644
index 0000000..3f0d7d8
--- /dev/null
+++ b/Modules/Atelier/tests/Feature/ReconciliacaoCampanhasTest.php
@@ -0,0 +1,325 @@
+<?php
+
+namespace Modules\Atelier\Tests\Feature;
+
+use Illuminate\Console\Scheduling\Schedule;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Artisan;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+use Modules\Atelier\Jobs\DispararCampanhaJob;
+use Modules\Atelier\Models\Campanha;
+use Modules\Atelier\Models\CampanhaEnvio;
+use Modules\Atelier\Models\ClienteCrm;
+use Modules\Atelier\Services\CampanhaReconciliador;
+use Modules\Core\Models\NotificationOutbox;
+use Modules\Core\Models\User;
+use Modules\Core\Notifications\Contracts\NotificationChannel;
+use Modules\Core\Notifications\DTO\NotificationSendResult;
+use Modules\Core\Notifications\NotificationChannelRegistry;
+use Modules\Core\Services\RegistoEmpresaService;
+use Modules\Core\Services\TenantManager;
+use Modules\Faturacao\Models\Cliente;
+use Tests\TestCase;
+
+/**
+ * Uma campanha "em processamento" nunca pode ficar presa: se o evento da
+ * Outbox se perder, o reconciliador (job + comando agendado) corrige o registo.
+ */
+class ReconciliacaoCampanhasTest extends TestCase
+{
+    use RefreshDatabase;
+
+    public function test_envio_pendente_com_outbox_aceite_passa_a_aceite_e_a_campanha_fecha(): void
+    {
+        [$campanha, $envio] = $this->campanhaComUmEnvio('500000901', NotificationOutbox::STATUS_ACCEPTED);
+
+        app(CampanhaReconciliador::class)->reconciliar($campanha);
+
+        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envio->fresh()->estado);
+        $this->assertSame('enviada', $campanha->fresh()->estado);
+        $this->assertNotNull($campanha->fresh()->enviada_em);
+    }
+
+    public function test_outbox_entregue_ou_lida_conta_como_aceite(): void
+    {
+        foreach ([NotificationOutbox::STATUS_DELIVERED, NotificationOutbox::STATUS_READ] as $i => $status) {
+            [$campanha, $envio] = $this->campanhaComUmEnvio('50000091'.$i, $status);
+
+            app(CampanhaReconciliador::class)->reconciliar($campanha);
+
+            $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envio->fresh()->estado, $status);
+            $this->assertSame('enviada', $campanha->fresh()->estado, $status);
+        }
+    }
+
+    public function test_outbox_falhada_ou_cancelada_marca_o_envio_como_falhou_e_a_campanha_conclui_com_falhas(): void
+    {
+        foreach ([NotificationOutbox::STATUS_DEAD_LETTER, NotificationOutbox::STATUS_FAILED, NotificationOutbox::STATUS_CANCELLED] as $i => $status) {
+            [$campanha, $envio] = $this->campanhaComUmEnvio('50000092'.$i, $status, 'Fornecedor indisponível.');
+
+            app(CampanhaReconciliador::class)->reconciliar($campanha);
+
+            $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envio->fresh()->estado, $status);
+            $this->assertSame('concluida_com_falhas', $campanha->fresh()->estado, $status);
+            $this->assertNotNull($envio->fresh()->erro);
+        }
+    }
+
+    public function test_outbox_ainda_a_processar_nao_altera_nada(): void
+    {
+        foreach ([NotificationOutbox::STATUS_PENDING, NotificationOutbox::STATUS_PROCESSING, NotificationOutbox::STATUS_RETRY_SCHEDULED] as $i => $status) {
+            [$campanha, $envio] = $this->campanhaComUmEnvio('50000093'.$i, $status);
+
+            app(CampanhaReconciliador::class)->reconciliar($campanha, abandonarOrfaos: true);
+
+            $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envio->fresh()->estado, $status);
+            $this->assertSame('em_processamento', $campanha->fresh()->estado, $status);
+        }
+    }
+
+    public function test_so_conta_a_outbox_da_propria_empresa(): void
+    {
+        [$campanha, $envio] = $this->campanhaComUmEnvio('500000941', NotificationOutbox::STATUS_PENDING);
+        $outraEmpresa = $this->registarAtelier('500000942');
+        $alheia = $this->outbox((int) $outraEmpresa->empresa_id, NotificationOutbox::STATUS_ACCEPTED);
+
+        // O envio (de uma empresa) aponta, por engano ou corrupção, para a Outbox de outra.
+        $envio->update(['notification_outbox_id' => $alheia->id]);
+
+        app(CampanhaReconciliador::class)->reconciliar($campanha);
+
+        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envio->fresh()->estado);
+        $this->assertSame('em_processamento', $campanha->fresh()->estado);
+    }
+
+    public function test_envio_orfao_so_e_abandonado_quando_pedido_e_depois_do_prazo(): void
+    {
+        $admin = $this->registarAtelier('500000951');
+        $empresaId = (int) $admin->empresa_id;
+        $campanha = $this->campanhaEmProcessamento($admin);
+
+        $antigo = $this->envio($campanha, $this->criarCliente($empresaId, 'Antigo', '923456781'), CampanhaEnvio::ESTADO_PENDENTE);
+        $recente = $this->envio($campanha, $this->criarCliente($empresaId, 'Recente', '923456782'), CampanhaEnvio::ESTADO_PENDENTE);
+        $this->envelhecer($antigo, CampanhaReconciliador::ENVIO_ORFAO_APOS_MINUTOS + 10);
+        $this->envelhecer($recente, 5);
+
+        // Sem o pedido explícito (é o que faz o job a meio de um envio) nada é abandonado.
+        app(CampanhaReconciliador::class)->reconciliar($campanha);
+        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $antigo->fresh()->estado);
+
+        app(CampanhaReconciliador::class)->reconciliar($campanha, abandonarOrfaos: true);
+
+        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $antigo->fresh()->estado);
+        $this->assertStringContainsString('Reenviar falhados', (string) $antigo->fresh()->erro);
+        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $recente->fresh()->estado);
+        // Ainda há um envio recente por resolver: a campanha continua em processamento.
+        $this->assertSame('em_processamento', $campanha->fresh()->estado);
+
+        $this->envelhecer($recente, CampanhaReconciliador::ENVIO_ORFAO_APOS_MINUTOS + 10);
+        app(CampanhaReconciliador::class)->reconciliar($campanha, abandonarOrfaos: true);
+
+        $this->assertSame('concluida_com_falhas', $campanha->fresh()->estado);
+    }
+
+    public function test_o_comando_reconcilia_campanhas_de_varias_empresas_sem_tenant(): void
+    {
+        [$campanhaA, $envioA] = $this->campanhaComUmEnvio('500000961', NotificationOutbox::STATUS_ACCEPTED);
+        [$campanhaB, $envioB] = $this->campanhaComUmEnvio('500000962', NotificationOutbox::STATUS_DEAD_LETTER, 'Recusada.');
+
+        $this->assertFalse(app(TenantManager::class)->has());
+
+        $this->assertSame(0, Artisan::call('atelier:campanhas-reconciliar'));
+        $this->assertStringContainsString('2', Artisan::output());
+
+        $this->assertSame('enviada', $campanhaA->fresh()->estado);
+        $this->assertSame('concluida_com_falhas', $campanhaB->fresh()->estado);
+        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envioA->fresh()->estado);
+        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envioB->fresh()->estado);
+    }
+
+    public function test_o_comando_nao_mexe_numa_campanha_que_um_job_esta_a_enviar(): void
+    {
+        [$campanha, $envio] = $this->campanhaComUmEnvio('500000971', NotificationOutbox::STATUS_ACCEPTED);
+
+        $lock = Cache::lock('atelier:campanha:'.$campanha->id, 60);
+        $this->assertTrue($lock->get());
+
+        try {
+            Artisan::call('atelier:campanhas-reconciliar');
+        } finally {
+            $lock->release();
+        }
+
+        $this->assertSame('em_processamento', $campanha->fresh()->estado);
+        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envio->fresh()->estado);
+
+        Artisan::call('atelier:campanhas-reconciliar');
+
+        $this->assertSame('enviada', $campanha->fresh()->estado);
+    }
+
+    public function test_o_comando_esta_agendado_de_dez_em_dez_minutos(): void
+    {
+        $evento = collect(app(Schedule::class)->events())
+            ->first(fn ($e) => $e->description === 'atelier:campanhas-reconciliar');
+
+        $this->assertNotNull($evento, 'O comando de reconciliação não está agendado.');
+        $this->assertSame('*/10 * * * *', $evento->expression);
+        $this->assertTrue($evento->withoutOverlapping);
+    }
+
+    public function test_o_job_tambem_recupera_um_evento_aceite_que_se_perdeu(): void
+    {
+        [$campanha, $envio] = $this->campanhaComUmEnvio('500000981', NotificationOutbox::STATUS_ACCEPTED);
+
+        app()->call([new DispararCampanhaJob($campanha->id), 'handle']);
+
+        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envio->fresh()->estado);
+        $this->assertSame('enviada', $campanha->fresh()->estado);
+    }
+
+    /**
+     * Regressão da causa raiz: com a fila síncrona (ou um worker muito rápido) o
+     * job da Outbox corria ANTES de o envio ficar ligado à Outbox, a listener não
+     * encontrava o envio e a campanha ficava em 'em_processamento'. O envio e a
+     * Outbox gravam-se agora na mesma transação, e o transporte só corre no commit.
+     */
+    public function test_o_envio_fica_ligado_a_outbox_antes_de_o_transporte_correr(): void
+    {
+        $admin = $this->registarAtelier('500000991');
+        $empresaId = (int) $admin->empresa_id;
+        $this->criarCliente($empresaId, 'Cliente ordem', '923456781');
+        $campanha = Campanha::create([
+            'empresa_id' => $empresaId,
+            'nome' => 'Campanha ordem',
+            'canal' => 'whatsapp',
+            'segmento' => 'todos',
+            'mensagem' => 'Mensagem de teste',
+            'estado' => 'rascunho',
+            'criada_por_id' => $admin->id,
+        ]);
+
+        $canal = new class implements NotificationChannel
+        {
+            public ?bool $envioJaLigado = null;
+
+            public function identifier(): string
+            {
+                return 'atelier.whatsapp';
+            }
+
+            public function send(NotificationOutbox $notification): NotificationSendResult
+            {
+                $this->envioJaLigado = CampanhaEnvio::query()
+                    ->where('notification_outbox_id', $notification->id)
+                    ->exists();
+
+                return NotificationSendResult::accepted('teste', 'mensagem-1', 202);
+            }
+        };
+        app(NotificationChannelRegistry::class)->register($canal);
+
+        // Fila síncrona (a dos testes): o job da Outbox corre no commit.
+        app()->call([new DispararCampanhaJob($campanha->id), 'handle']);
+
+        $this->assertTrue($canal->envioJaLigado, 'O transporte correu antes de o envio apontar para a Outbox.');
+        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, CampanhaEnvio::query()->where('campanha_id', $campanha->id)->firstOrFail()->estado);
+        $this->assertSame('enviada', $campanha->fresh()->estado);
+    }
+
+    // ---------------------------------------------------------------------
+    // Preparação
+    // ---------------------------------------------------------------------
+
+    /** @return array{0: Campanha, 1: CampanhaEnvio} */
+    protected function campanhaComUmEnvio(string $nif, string $statusOutbox, ?string $erro = null): array
+    {
+        $admin = $this->registarAtelier($nif);
+        $empresaId = (int) $admin->empresa_id;
+
+        $campanha = $this->campanhaEmProcessamento($admin);
+        $cliente = $this->criarCliente($empresaId, 'Cliente '.$nif, '923456781');
+        $envio = $this->envio($campanha, $cliente, CampanhaEnvio::ESTADO_PENDENTE, $this->outbox($empresaId, $statusOutbox, $erro));
+
+        return [$campanha, $envio];
+    }
+
+    protected function registarAtelier(string $nif): User
+    {
+        $admin = app(RegistoEmpresaService::class)->registar(
+            ['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif],
+            ['name' => 'Admin '.$nif, 'email' => "admin{$nif}@example.test", 'password' => 'Senha-segura-123'],
+            ['atelier'],
+        );
+
+        // Como num worker: sem tenant ativo; os modelos levam empresa_id explícito.
+        app(TenantManager::class)->clear();
+
+        return $admin;
+    }
+
+    protected function criarCliente(int $empresaId, string $nome, string $telefone): Cliente
+    {
+        $cliente = Cliente::create(['empresa_id' => $empresaId, 'nome' => $nome, 'telefone' => $telefone]);
+
+        ClienteCrm::create([
+            'empresa_id' => $empresaId,
+            'cliente_id' => $cliente->id,
+            'aceita_marketing' => true,
+            'consentimento_em' => now(),
+            'consentimento_origem' => 'teste',
+        ]);
+
+        return $cliente;
+    }
+
+    protected function campanhaEmProcessamento(User $admin): Campanha
+    {
+        return Campanha::create([
+            'empresa_id' => (int) $admin->empresa_id,
+            'nome' => 'Campanha em processamento',
+            'canal' => 'whatsapp',
+            'segmento' => 'todos',
+            'mensagem' => 'Mensagem de teste',
+            'estado' => 'em_processamento',
+            'criada_por_id' => $admin->id,
+        ]);
+    }
+
+    protected function outbox(int $empresaId, string $status, ?string $erro = null): NotificationOutbox
+    {
+        return NotificationOutbox::withoutGlobalScopes()->create([
+            'empresa_id' => $empresaId,
+            'idempotency_key' => 'teste-'.Str::uuid(),
+            'channel' => 'atelier.whatsapp',
+            'recipient' => '244923456781',
+            'payload' => ['mensagem' => 'Mensagem de teste'],
+            'status' => $status,
+            'max_attempts' => 5,
+            'available_at' => now(),
+            'last_error' => $erro,
+        ]);
+    }
+
+    protected function envio(Campanha $campanha, Cliente $cliente, string $estado, ?NotificationOutbox $outbox = null): CampanhaEnvio
+    {
+        return CampanhaEnvio::create([
+            'campanha_id' => $campanha->id,
+            'cliente_id' => $cliente->id,
+            'canal' => $campanha->canal,
+            'estado' => $estado,
+            'tentativa' => 1,
+            'notification_outbox_id' => $outbox?->id,
+        ]);
+    }
+
+    /** Recua o updated_at (o Eloquent sobrepõe-no, por isso vai pelo query builder). */
+    protected function envelhecer(CampanhaEnvio $envio, int $minutos): void
+    {
+        DB::table('atelier_campanha_envios')
+            ->where('id', $envio->id)
+            ->update(['updated_at' => now()->subMinutes($minutos)]);
+    }
+}
PATCH_V3_ATELIER

MSG_CI="ci: mover o composer audit para um workflow próprio (corre também todos os dias)"
MSG_AT="fix(atelier): campanhas nunca ficam presas em processamento (ligação atómica à Outbox + reconciliador agendado)"

aplicar() {
  local ficheiro="$1" mensagem="$2"
  # Idempotência pelo assunto do commit (os patches não se podem testar com "apply -R").
  if git log --format=%s "$COMMIT_BASE"..HEAD | grep -Fxq -- "$mensagem"; then
    info "já aplicado, a saltar: $mensagem"
    return 0
  fi
  git apply --check "$ficheiro" 2>/dev/null \
    || erro "o patch não aplica limpo (a branch mudou nestes ficheiros?): $mensagem"
  git apply --index "$ficheiro"
  git commit -q -m "$mensagem"
  info "commit: $mensagem"
}

aplicar "$TMP/01-ci.patch"      "$MSG_CI"
aplicar "$TMP/02-atelier.patch" "$MSG_AT"

# --- 2. Validação local (se as dependências estiverem instaladas) --------------
export XDEBUG_MODE=off
if [[ -x vendor/bin/pint ]]; then
  info "Pint nos ficheiros PHP alterados pela PR"
  git fetch -q origin main 2>/dev/null || true
  BASE_REF="origin/main"; git rev-parse -q --verify "$BASE_REF" >/dev/null || BASE_REF="$COMMIT_BASE"
  mapfile -t FICHEIROS < <(git diff --name-only --diff-filter=d "$BASE_REF"...HEAD -- '*.php')
  if ((${#FICHEIROS[@]})); then
    vendor/bin/pint --test "${FICHEIROS[@]}" || erro "o Pint reprova — não faças push."
  fi
else
  echo "AVISO: vendor/bin/pint não existe (corre 'composer install'); validação de estilo saltada."
fi

if [[ -f vendor/autoload.php ]]; then
  info "Testes de reenvio e de reconciliação de campanhas"
  php artisan test Modules/Atelier/tests/Feature/ReconciliacaoCampanhasTest.php \
                   Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php \
                   Modules/Atelier/tests/Feature/ReenvioCampanhaHttpTest.php \
                   Modules/Atelier/tests/Feature/ReenvioCampanhaRegrasTest.php \
    || erro "os testes de campanhas falharam — não faças push."
  info "Suite completa (como no CI, sem o grupo auditoria)"
  php artisan test --exclude-group=auditoria || erro "a suite falhou — não faças push."
else
  echo "AVISO: vendor/ não existe; testes saltados."
fi

# --- 3. Push (opcional) --------------------------------------------------------
if ((PUSH)); then
  info "git push origin $RAMO_ESPERADO"
  git push origin "$RAMO_ESPERADO" || erro "o push falhou (a branch avançou no GitHub?). Faz: git pull --rebase origin $RAMO_ESPERADO  e repete."
  info "Feito. No GitHub vão aparecer 3 verificações: qualidade, testes-mysql e composer-audit."
else
  echo
  info "Concluído localmente. Quando quiseres enviar:  git push origin $RAMO_ESPERADO"
fi
