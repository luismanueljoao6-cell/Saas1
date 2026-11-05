#!/usr/bin/env bash
# =============================================================================
# corrigir-pr33.sh — correções da PR #33 (branch feat/faturacao-fiscal-core)
#
#   1. Pint: repõe o estilo do preset Laravel nos 3 ficheiros que reprovavam
#      (a correção anterior tirou o espaço de "! $x" — o preset exige-o — e
#      alterou construtores vazios e "new X()").
#   2. Reenvio de campanhas do Atelier: o pedido passa a ser aceite de forma
#      atómica (lock + tabela atelier_campanha_reenvios + token). O job só
#      reenvia se levar o token de um pedido aberto, por isso jobs antigos,
#      duplicados ou expirados nunca reenviam nada. O histórico fica guardado.
#
# Uso (na raiz do repositório, com a branch da PR em checkout):
#     bash corrigir-pr33.sh           # aplica, valida e faz commit local
#     bash corrigir-pr33.sh --push    # idem + git push (sem --force)
#
# Nunca toca na main, nunca faz merge, nunca faz force-push.
# É idempotente: se um patch já estiver aplicado, salta-o.
# =============================================================================
set -euo pipefail

RAMO_ESPERADO="feat/faturacao-fiscal-core"
COMMIT_BASE="45ea488"
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
[[ -z "$(git status --porcelain)" ]] || erro "há alterações por gravar. Faz commit ou stash antes de continuar."
git merge-base --is-ancestor "$COMMIT_BASE" HEAD 2>/dev/null \
  || erro "o commit $COMMIT_BASE não está no histórico desta branch. Faz: git pull --ff-only origin $RAMO_ESPERADO"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# --- 1. Patches ---------------------------------------------------------------
cat > "$TMP/01-estilo.patch" <<'PATCH_01_ESTILO'
diff --git a/Modules/Atelier/app/Jobs/DispararCampanhaJob.php b/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
index 65b40e3..1b1096e 100644
--- a/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
+++ b/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
@@ -52,9 +52,7 @@ class DispararCampanhaJob implements ShouldQueue
 
     public int $timeout = 900;
 
-    public function __construct(protected int $campanhaId, protected bool $reenvioExplicito = false)
-    {
-    }
+    public function __construct(protected int $campanhaId, protected bool $reenvioExplicito = false) {}
 
     public function handle(
         TenantManager $tenantManager,
@@ -64,7 +62,7 @@ public function handle(
     ): void {
         $lock = Cache::lock('atelier:campanha:'.$this->campanhaId, $this->timeout + 60);
 
-        if (!$lock->get()) {
+        if (! $lock->get()) {
             // Já há um job a processar esta campanha.
             return;
         }
@@ -80,13 +78,13 @@ protected function processar(CrmService $crmService, NotificadorClienteService $
     {
         $campanha = Campanha::find($this->campanhaId);
 
-        if (!$campanha || $campanha->estado === 'cancelada') {
+        if (! $campanha || $campanha->estado === 'cancelada') {
             return;
         }
 
         $empresa = Empresa::find($campanha->empresa_id);
 
-        if (!$empresa || !$empresa->temAcesso() || !$empresa->temServico('atelier')) {
+        if (! $empresa || ! $empresa->temAcesso() || ! $empresa->temServico('atelier')) {
             Log::info('Atelier: campanha não enviada — empresa sem acesso ou sem o serviço atelier', [
                 'campanha_id' => $campanha->id,
                 'empresa_id' => $campanha->empresa_id,
@@ -109,7 +107,7 @@ protected function processar(CrmService $crmService, NotificadorClienteService $
                 ->whereKey($envioOutbox->notification_outbox_id)
                 ->first();
 
-            if (!$outbox || ! in_array($outbox->status, [
+            if (! $outbox || ! in_array($outbox->status, [
                 NotificationOutbox::STATUS_FAILED,
                 NotificationOutbox::STATUS_DEAD_LETTER,
             ], true)) {
@@ -126,7 +124,7 @@ protected function processar(CrmService $crmService, NotificadorClienteService $
             ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
             ->exists();
 
-        if ($campanha->estado === 'em_processamento' && !$temPendenteReconhecido) {
+        if ($campanha->estado === 'em_processamento' && ! $temPendenteReconhecido) {
             $temFalhaReconhecida = $campanha->envios()
                 ->where('estado', CampanhaEnvio::ESTADO_FALHOU)
                 ->exists();
@@ -141,7 +139,7 @@ protected function processar(CrmService $crmService, NotificadorClienteService $
         // Um job repetido pela fila/agendador nunca pode transformar uma
         // reconciliação terminal num novo envio. Só o botão de reenvio manual
         // autoriza uma nova tentativa para campanhas já concluídas.
-        if (!$this->reenvioExplicito && in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true)) {
+        if (! $this->reenvioExplicito && in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true)) {
             return;
         }
 
@@ -180,7 +178,7 @@ protected function processar(CrmService $crmService, NotificadorClienteService $
             if ($envio->notification_outbox_id !== null) {
                 $outboxAnterior = $envio->notificationOutbox;
 
-                if (!$outboxAnterior || ! in_array($outboxAnterior->status, [
+                if (! $outboxAnterior || ! in_array($outboxAnterior->status, [
                     NotificationOutbox::STATUS_FAILED,
                     NotificationOutbox::STATUS_DEAD_LETTER,
                 ], true)) {
diff --git a/Modules/Core/app/Notifications/NotificationProcessor.php b/Modules/Core/app/Notifications/NotificationProcessor.php
index f47f1f0..52d3938 100644
--- a/Modules/Core/app/Notifications/NotificationProcessor.php
+++ b/Modules/Core/app/Notifications/NotificationProcessor.php
@@ -16,8 +16,7 @@ class NotificationProcessor
     public function __construct(
         private readonly TenantManager $tenants,
         private readonly NotificationChannelRegistry $channels,
-    ) {
-    }
+    ) {}
 
     public function process(int $notificationId, int $empresaId): void
     {
@@ -49,7 +48,7 @@ private function processWithinTenant(int $notificationId, int $empresaId): void
                 ->lockForUpdate()
                 ->first();
 
-            if (!$notification || $notification->isTerminal()) {
+            if (! $notification || $notification->isTerminal()) {
                 return null;
             }
 
@@ -103,7 +102,7 @@ private function processWithinTenant(int $notificationId, int $empresaId): void
             ];
         });
 
-        if (!$claim) {
+        if (! $claim) {
             return;
         }
 
@@ -126,7 +125,7 @@ private function processWithinTenant(int $notificationId, int $empresaId): void
                 ->lockForUpdate()
                 ->first();
 
-            if (!$notification) {
+            if (! $notification) {
                 return;
             }
 
@@ -184,7 +183,7 @@ private function recordFailure(array $claim, int $empresaId, string $message): v
                 ->lockForUpdate()
                 ->first();
 
-            if (!$notification) {
+            if (! $notification) {
                 return;
             }
 
@@ -235,7 +234,7 @@ private function scheduleRetry(
             'finished_at' => now(),
         ])->save();
 
-        if (!$terminal) {
+        if (! $terminal) {
             ProcessNotificationJob::dispatch($notification->id, (int) $notification->empresa_id)
                 ->delay(now()->addSeconds($delay))
                 ->afterCommit();
diff --git a/Modules/Core/tests/Unit/NotificationChannelRegistryTest.php b/Modules/Core/tests/Unit/NotificationChannelRegistryTest.php
index 5777e88..f3a0861 100644
--- a/Modules/Core/tests/Unit/NotificationChannelRegistryTest.php
+++ b/Modules/Core/tests/Unit/NotificationChannelRegistryTest.php
@@ -13,7 +13,8 @@ class NotificationChannelRegistryTest extends TestCase
 {
     public function test_registered_channel_can_be_resolved_by_identifier(): void
     {
-        $channel = new class implements NotificationChannel {
+        $channel = new class implements NotificationChannel
+        {
             public function identifier(): string
             {
                 return 'fake';
@@ -25,7 +26,7 @@ public function send(NotificationOutbox $notification): NotificationSendResult
             }
         };
 
-        $registry = new NotificationChannelRegistry();
+        $registry = new NotificationChannelRegistry;
         $registry->register($channel);
 
         $this->assertTrue($registry->has('fake'));
@@ -34,7 +35,7 @@ public function send(NotificationOutbox $notification): NotificationSendResult
 
     public function test_unknown_channel_is_not_silently_resolved(): void
     {
-        $registry = new NotificationChannelRegistry();
+        $registry = new NotificationChannelRegistry;
 
         $this->assertFalse($registry->has('missing'));
         $this->expectException(InvalidArgumentException::class);
PATCH_01_ESTILO

cat > "$TMP/02-reenvio.patch" <<'PATCH_02_REENVIO'
diff --git a/Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php b/Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php
index 69d3f96..2a13624 100644
--- a/Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php
+++ b/Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php
@@ -2,13 +2,19 @@
 
 namespace Modules\Atelier\Http\Controllers\Admin;
 
+use Illuminate\Database\UniqueConstraintViolationException;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Routing\Controller;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
 use Illuminate\View\View;
 use Modules\Atelier\Http\Requests\GuardarCampanhaRequest;
 use Modules\Atelier\Jobs\DispararCampanhaJob;
 use Modules\Atelier\Models\Campanha;
+use Modules\Atelier\Models\CampanhaEnvio;
+use Modules\Atelier\Models\CampanhaReenvio;
+use Throwable;
 
 /**
  * IMPORTANTE: {campanha} chega como int, nunca como Campanha tipado (route
@@ -64,27 +70,66 @@ public function enviarAgora(Request $request, int $campanha): RedirectResponse
     {
         $this->autorizar($request);
 
-        $campanha = Campanha::findOrFail($campanha);
-
-        abort_if($campanha->estado === 'cancelada', 422, 'Esta campanha foi cancelada.');
-
-        // Campanha já enviada só pode ser relançada para reenviar os envios
-        // que falharam (o job ignora os já enviados e respeita o consentimento atual).
-        $temFalhas = $campanha->envios()->where('estado', 'falhou')->exists();
-
-        abort_if(
-            ! in_array($campanha->estado, ['rascunho', 'agendada', 'enviada', 'concluida_com_falhas'], true)
-                || (in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true) && ! $temFalhas),
-            422,
-            'Esta campanha não pode ser enviada ou não tem envios falhados para repetir.'
-        );
-
-        // Um clique duplo despacha dois jobs, mas o job tem um lock por
-        // campanha e é idempotente: o segundo não reenvia nada.
-        DispararCampanhaJob::dispatch(
-            $campanha->id,
-            in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true),
-        );
+        // O pedido é validado E registado na mesma transação, com lock de linha
+        // na campanha: dois pedidos simultâneos são serializados aqui e só o
+        // primeiro fica com o reenvio (o segundo recebe 409). O job nunca decide
+        // sozinho se há reenvio — só corre se levar o token deste pedido.
+        [$campanha, $pedido] = DB::transaction(function () use ($request, $campanha): array {
+            $campanha = Campanha::query()->lockForUpdate()->findOrFail($campanha);
+
+            abort_if($campanha->estado === 'cancelada', 422, 'Esta campanha foi cancelada.');
+
+            $reenvio = in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true);
+            $temFalhas = $campanha->envios()->where('estado', CampanhaEnvio::ESTADO_FALHOU)->exists();
+
+            abort_if(
+                ! ($reenvio || in_array($campanha->estado, ['rascunho', 'agendada'], true))
+                    || ($reenvio && ! $temFalhas),
+                422,
+                'Esta campanha não pode ser enviada ou não tem envios falhados para repetir.'
+            );
+
+            // Primeiro envio: o lock por campanha e a idempotência dos envios
+            // já tornam um clique duplo inofensivo.
+            if (! $reenvio) {
+                return [$campanha, null];
+            }
+
+            CampanhaReenvio::expirarAbandonados($campanha->id);
+
+            abort_if(
+                CampanhaReenvio::query()->where('campanha_id', $campanha->id)->where('aberto', true)->exists(),
+                409,
+                'Já existe um reenvio em curso para esta campanha.'
+            );
+
+            try {
+                $pedido = CampanhaReenvio::create([
+                    'empresa_id' => $campanha->empresa_id,
+                    'campanha_id' => $campanha->id,
+                    'token' => (string) Str::uuid(),
+                    'estado' => CampanhaReenvio::ACEITE,
+                    'aberto' => true,
+                    'solicitado_por_id' => $request->user()->id,
+                    'solicitado_em' => now(),
+                ]);
+            } catch (UniqueConstraintViolationException) {
+                // Rede de segurança da unique (campanha_id, aberto) caso o lock
+                // de linha não esteja disponível no driver.
+                abort(409, 'Já existe um reenvio em curso para esta campanha.');
+            }
+
+            return [$campanha, $pedido];
+        });
+
+        try {
+            DispararCampanhaJob::dispatch($campanha->id, $pedido?->token);
+        } catch (Throwable $e) {
+            // Sem job na fila o pedido ficaria aberto e bloquearia novos reenvios.
+            $pedido?->fechar(CampanhaReenvio::FALHADO, 'Não foi possível colocar o job na fila: '.$e->getMessage());
+
+            throw $e;
+        }
 
         return back()->with('sucesso', 'Envio iniciado — os estados de entrega vão aparecer aqui à medida que forem processados.');
     }
diff --git a/Modules/Atelier/app/Jobs/DispararCampanhaJob.php b/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
index 1b1096e..24786fa 100644
--- a/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
+++ b/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
@@ -8,9 +8,11 @@
 use Illuminate\Queue\InteractsWithQueue;
 use Illuminate\Queue\SerializesModels;
 use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Log;
 use Modules\Atelier\Models\Campanha;
 use Modules\Atelier\Models\CampanhaEnvio;
+use Modules\Atelier\Models\CampanhaReenvio;
 use Modules\Atelier\Services\CrmService;
 use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
 use Modules\Atelier\Services\PortalLinkService;
@@ -36,7 +38,10 @@
  * duplo, ou o agendador a re-despachar enquanto o anterior ainda corre).
  *
  * Reenvio: numa campanha já 'enviada' só se reprocessam os envios que
- * falharam/ficaram pendentes — nunca se alarga o público.
+ * falharam/ficaram pendentes — nunca se alarga o público. Só um pedido
+ * manual aceite (CampanhaReenvio, identificado por $reenvioToken) autoriza
+ * esse reenvio: um job sem token, repetido, obsoleto ou duplicado nunca
+ * reenvia nada. Cada pedido autoriza no máximo UMA execução.
  *
  * Marketing: cada mensagem leva o link para o cliente deixar de receber
  * campanhas (Lei n.º 22/11), e o público é sempre só quem tem consentimento.
@@ -52,7 +57,7 @@ class DispararCampanhaJob implements ShouldQueue
 
     public int $timeout = 900;
 
-    public function __construct(protected int $campanhaId, protected bool $reenvioExplicito = false) {}
+    public function __construct(protected int $campanhaId, protected ?string $reenvioToken = null) {}
 
     public function handle(
         TenantManager $tenantManager,
@@ -63,7 +68,11 @@ public function handle(
         $lock = Cache::lock('atelier:campanha:'.$this->campanhaId, $this->timeout + 60);
 
         if (! $lock->get()) {
-            // Já há um job a processar esta campanha.
+            // Já há um job a processar esta campanha. Um pedido de reenvio
+            // não pode ficar aberto sem ninguém o tratar: volta a tentar mais
+            // tarde ou, esgotadas as tentativas, fecha-se como ignorado.
+            $this->adiarOuIgnorarPedido();
+
             return;
         }
 
@@ -76,10 +85,104 @@ public function handle(
 
     protected function processar(CrmService $crmService, NotificadorClienteService $notificador, PortalLinkService $linkService): void
     {
+        $pedido = $this->reivindicarPedidoDeReenvio();
+
+        // Com token, só corre se o pedido existir e ainda estiver aberto:
+        // um job obsoleto (pedido já usado, expirado ou inexistente) sai aqui.
+        if ($this->reenvioToken !== null && $pedido === null) {
+            return;
+        }
+
+        $motivoIgnorado = $this->executar($crmService, $notificador, $linkService, $pedido !== null);
+
+        $pedido?->fechar(
+            $motivoIgnorado === null ? CampanhaReenvio::CONCLUIDO : CampanhaReenvio::IGNORADO,
+            $motivoIgnorado,
+        );
+    }
+
+    /**
+     * Marca o pedido como iniciado sob lock de linha. Devolve null quando o
+     * job não tem token (envio normal) ou quando o pedido não é utilizável.
+     * Uma repetição do MESMO job (retry da fila após falha) retoma o pedido
+     * 'iniciado'; um pedido fechado nunca volta a abrir.
+     */
+    protected function reivindicarPedidoDeReenvio(): ?CampanhaReenvio
+    {
+        if ($this->reenvioToken === null) {
+            return null;
+        }
+
+        return DB::transaction(function (): ?CampanhaReenvio {
+            $pedido = CampanhaReenvio::query()
+                ->where('campanha_id', $this->campanhaId)
+                ->where('token', $this->reenvioToken)
+                ->lockForUpdate()
+                ->first();
+
+            if (! $pedido || ! in_array($pedido->estado, [CampanhaReenvio::ACEITE, CampanhaReenvio::INICIADO], true)) {
+                Log::info('Atelier: job de reenvio ignorado — pedido inexistente, já usado ou expirado', [
+                    'campanha_id' => $this->campanhaId,
+                    'estado_pedido' => $pedido?->estado,
+                ]);
+
+                return null;
+            }
+
+            if ($pedido->estado === CampanhaReenvio::ACEITE) {
+                $pedido->update(['estado' => CampanhaReenvio::INICIADO, 'iniciado_em' => now()]);
+            }
+
+            return $pedido;
+        });
+    }
+
+    protected function adiarOuIgnorarPedido(): void
+    {
+        if ($this->reenvioToken === null) {
+            return;
+        }
+
+        if ($this->job !== null && $this->attempts() < $this->tries) {
+            $this->release(30);
+
+            return;
+        }
+
+        $this->fecharPedido(CampanhaReenvio::IGNORADO, 'A campanha continuou ocupada por outro job.');
+    }
+
+    /** Chamado pela fila quando as tentativas se esgotam: nunca deixar o pedido aberto para sempre. */
+    public function failed(Throwable $exception): void
+    {
+        $this->fecharPedido(CampanhaReenvio::FALHADO, $exception->getMessage());
+    }
+
+    protected function fecharPedido(string $estado, ?string $motivo): void
+    {
+        if ($this->reenvioToken === null) {
+            return;
+        }
+
+        app(TenantManager::class)->semTenant(function () use ($estado, $motivo): void {
+            CampanhaReenvio::query()
+                ->where('campanha_id', $this->campanhaId)
+                ->where('token', $this->reenvioToken)
+                ->first()
+                ?->fechar($estado, $motivo);
+        });
+    }
+
+    protected function executar(
+        CrmService $crmService,
+        NotificadorClienteService $notificador,
+        PortalLinkService $linkService,
+        bool $reenvioAutorizado,
+    ): ?string {
         $campanha = Campanha::find($this->campanhaId);
 
         if (! $campanha || $campanha->estado === 'cancelada') {
-            return;
+            return 'Campanha inexistente ou cancelada.';
         }
 
         $empresa = Empresa::find($campanha->empresa_id);
@@ -90,7 +193,7 @@ protected function processar(CrmService $crmService, NotificadorClienteService $
                 'empresa_id' => $campanha->empresa_id,
             ]);
 
-            return;
+            return 'Empresa sem acesso ou sem o serviço atelier.';
         }
 
         // Reconcilia estados terminais da Outbox mesmo quando o evento
@@ -139,11 +242,11 @@ protected function processar(CrmService $crmService, NotificadorClienteService $
         // Um job repetido pela fila/agendador nunca pode transformar uma
         // reconciliação terminal num novo envio. Só o botão de reenvio manual
         // autoriza uma nova tentativa para campanhas já concluídas.
-        if (! $this->reenvioExplicito && in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true)) {
-            return;
+        if (! $reenvioAutorizado && in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true)) {
+            return null;
         }
 
-        $reenvio = $this->reenvioExplicito
+        $reenvio = $reenvioAutorizado
             && in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true);
         $idsPorReenviar = collect();
 
@@ -151,7 +254,7 @@ protected function processar(CrmService $crmService, NotificadorClienteService $
             $idsPorReenviar = $campanha->envios()->whereIn('estado', [CampanhaEnvio::ESTADO_FALHOU, CampanhaEnvio::ESTADO_PENDENTE])->pluck('cliente_id');
 
             if ($idsPorReenviar->isEmpty()) {
-                return;
+                return 'Não havia envios falhados ou pendentes para repetir.';
             }
         }
 
@@ -247,5 +350,7 @@ protected function processar(CrmService $crmService, NotificadorClienteService $
                 : ($temFalha ? 'concluida_com_falhas' : 'enviada'),
             'enviada_em' => $temPendente ? null : ($campanha->enviada_em ?? now()),
         ]);
+
+        return null;
     }
 }
diff --git a/Modules/Atelier/app/Models/Campanha.php b/Modules/Atelier/app/Models/Campanha.php
index 7567d86..da437b2 100644
--- a/Modules/Atelier/app/Models/Campanha.php
+++ b/Modules/Atelier/app/Models/Campanha.php
@@ -45,6 +45,11 @@ public function envios(): HasMany
         return $this->hasMany(CampanhaEnvio::class);
     }
 
+    public function reenvios(): HasMany
+    {
+        return $this->hasMany(CampanhaReenvio::class);
+    }
+
     public function segmentoRotulo(): string
     {
         return match ($this->segmento) {
diff --git a/Modules/Atelier/app/Models/CampanhaReenvio.php b/Modules/Atelier/app/Models/CampanhaReenvio.php
new file mode 100644
index 0000000..711124b
--- /dev/null
+++ b/Modules/Atelier/app/Models/CampanhaReenvio.php
@@ -0,0 +1,112 @@
+<?php
+
+namespace Modules\Atelier\Models;
+
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Eloquent\Relations\BelongsTo;
+use Modules\Core\Models\User;
+use Modules\Core\Traits\BelongsToTenant;
+
+/**
+ * Um pedido manual de reenvio de campanha ("Reenviar falhados").
+ *
+ * É criado de forma atómica no momento em que o pedido é aceite (ver
+ * CampanhaController::enviarAgora) e é a ÚNICA autorização que um job tem
+ * para reenviar uma campanha já concluída: o job leva o token e recusa-se
+ * a correr se o pedido não existir, já tiver fechado ou tiver expirado.
+ * Fica guardado como histórico das tentativas manuais.
+ */
+class CampanhaReenvio extends Model
+{
+    use BelongsToTenant;
+
+    public const ACEITE = 'aceite';
+
+    public const INICIADO = 'iniciado';
+
+    public const CONCLUIDO = 'concluido';
+
+    public const IGNORADO = 'ignorado';
+
+    public const FALHADO = 'falhado';
+
+    public const EXPIRADO = 'expirado';
+
+    /** Um pedido aberto há mais tempo do que isto é considerado abandonado (job perdido ou worker morto). */
+    public const VALIDADE_MINUTOS = 60;
+
+    protected $table = 'atelier_campanha_reenvios';
+
+    protected $fillable = [
+        'empresa_id',
+        'campanha_id',
+        'token',
+        'estado',
+        'aberto',
+        'solicitado_por_id',
+        'solicitado_em',
+        'iniciado_em',
+        'concluido_em',
+        'motivo',
+    ];
+
+    protected function casts(): array
+    {
+        return [
+            'aberto' => 'boolean',
+            'solicitado_em' => 'datetime',
+            'iniciado_em' => 'datetime',
+            'concluido_em' => 'datetime',
+        ];
+    }
+
+    public function campanha(): BelongsTo
+    {
+        return $this->belongsTo(Campanha::class);
+    }
+
+    public function solicitadoPor(): BelongsTo
+    {
+        return $this->belongsTo(User::class, 'solicitado_por_id');
+    }
+
+    /**
+     * Fecha o pedido de forma condicional (só se ainda estiver aberto), por
+     * isso é seguro chamar mais do que uma vez e nunca reabre nem sobrepõe
+     * um estado final já registado.
+     */
+    public function fechar(string $estado, ?string $motivo = null): bool
+    {
+        return $this->newQuery()
+            ->withoutGlobalScopes()
+            ->whereKey($this->getKey())
+            ->where('aberto', true)
+            ->update([
+                'estado' => $estado,
+                'aberto' => null,
+                'concluido_em' => now(),
+                'motivo' => $motivo !== null ? mb_substr($motivo, 0, 500) : null,
+                'updated_at' => now(),
+            ]) === 1;
+    }
+
+    /** Fecha como expirados os pedidos abertos e abandonados de uma campanha. */
+    public static function expirarAbandonados(int $campanhaId): int
+    {
+        $limite = now()->subMinutes(self::VALIDADE_MINUTOS);
+
+        return static::query()
+            ->where('campanha_id', $campanhaId)
+            ->where('aberto', true)
+            ->where(fn ($q) => $q
+                ->where('iniciado_em', '<', $limite)
+                ->orWhere(fn ($q) => $q->whereNull('iniciado_em')->where('solicitado_em', '<', $limite)))
+            ->update([
+                'estado' => self::EXPIRADO,
+                'aberto' => null,
+                'concluido_em' => now(),
+                'motivo' => 'Pedido abandonado: o job não terminou dentro do prazo.',
+                'updated_at' => now(),
+            ]);
+    }
+}
diff --git a/Modules/Atelier/database/migrations/2026_11_03_000001_create_atelier_campanha_reenvios_table.php b/Modules/Atelier/database/migrations/2026_11_03_000001_create_atelier_campanha_reenvios_table.php
new file mode 100644
index 0000000..8f38ec7
--- /dev/null
+++ b/Modules/Atelier/database/migrations/2026_11_03_000001_create_atelier_campanha_reenvios_table.php
@@ -0,0 +1,37 @@
+<?php
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('atelier_campanha_reenvios', function (Blueprint $table): void {
+            $table->id();
+            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
+            $table->foreignId('campanha_id')->constrained('atelier_campanhas')->cascadeOnDelete();
+            $table->uuid('token')->unique();
+            $table->string('estado', 20)->default('aceite');
+            // true enquanto o pedido está aceite/iniciado; NULL quando fecha.
+            // A unique (campanha_id, aberto) permite no máximo UM pedido aberto
+            // por campanha, e vários fechados (NULL não colide em MySQL/SQLite).
+            $table->boolean('aberto')->nullable()->default(true);
+            $table->foreignId('solicitado_por_id')->nullable()->constrained('users')->nullOnDelete();
+            $table->timestamp('solicitado_em');
+            $table->timestamp('iniciado_em')->nullable();
+            $table->timestamp('concluido_em')->nullable();
+            $table->string('motivo', 500)->nullable();
+            $table->timestamps();
+
+            $table->unique(['campanha_id', 'aberto'], 'atelier_campanha_reenvio_aberto_unique');
+            $table->index(['empresa_id', 'campanha_id'], 'atelier_campanha_reenvio_empresa_idx');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('atelier_campanha_reenvios');
+    }
+};
diff --git a/Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php b/Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php
new file mode 100644
index 0000000..852e7ba
--- /dev/null
+++ b/Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php
@@ -0,0 +1,345 @@
+<?php
+
+namespace Modules\Atelier\Tests\Feature;
+
+use Illuminate\Database\UniqueConstraintViolationException;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\Queue;
+use Modules\Atelier\Http\Controllers\Admin\CampanhaController;
+use Modules\Atelier\Jobs\DispararCampanhaJob;
+use Modules\Atelier\Models\Campanha;
+use Modules\Atelier\Models\CampanhaEnvio;
+use Modules\Atelier\Models\CampanhaReenvio;
+use Modules\Atelier\Models\ClienteCrm;
+use Modules\Atelier\Services\CrmService;
+use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
+use Modules\Atelier\Services\PortalLinkService;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Models\NotificationOutbox;
+use Modules\Core\Models\User;
+use Modules\Core\Services\TenantManager;
+use Modules\Faturacao\Models\Cliente;
+use ReflectionProperty;
+use RuntimeException;
+use Spatie\Permission\Models\Role;
+use Spatie\Permission\PermissionRegistrar;
+use Symfony\Component\HttpKernel\Exception\HttpException;
+use Tests\TestCase;
+
+/**
+ * Reenvio manual de campanhas: dois pedidos simultâneos, jobs antigos e
+ * histórico das tentativas.
+ */
+class ReenvioCampanhaConcorrenciaTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected User $admin;
+
+    protected Campanha $campanha;
+
+    protected CampanhaEnvio $envio;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Atelier Reenvio', 'nif' => '400000501']);
+        $this->empresa->aderirServicos(['atelier']);
+        app(TenantManager::class)->set($this->empresa->id);
+
+        $this->admin = User::create([
+            'empresa_id' => $this->empresa->id,
+            'name' => 'Admin Reenvio',
+            'email' => 'admin.reenvio@example.test',
+            'password' => bcrypt('segredo-123'),
+        ]);
+        app(PermissionRegistrar::class)->setPermissionsTeamId($this->empresa->id);
+        Role::create(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $this->empresa->id]);
+        $this->admin->assignRole('Administrador');
+
+        $cliente = Cliente::create([
+            'empresa_id' => $this->empresa->id,
+            'nome' => 'Cliente Reenvio',
+            'telefone' => '923456789',
+        ]);
+        ClienteCrm::create([
+            'empresa_id' => $this->empresa->id,
+            'cliente_id' => $cliente->id,
+            'aceita_marketing' => true,
+            'consentimento_em' => now()->subDay(),
+        ]);
+
+        $this->campanha = Campanha::create([
+            'empresa_id' => $this->empresa->id,
+            'nome' => 'Campanha de reenvio',
+            'canal' => 'whatsapp',
+            'segmento' => 'todos',
+            'mensagem' => 'Mensagem de teste',
+            'estado' => 'concluida_com_falhas',
+            'enviada_em' => now()->subHour(),
+        ]);
+        $this->envio = CampanhaEnvio::create([
+            'campanha_id' => $this->campanha->id,
+            'cliente_id' => $cliente->id,
+            'canal' => 'whatsapp',
+            'estado' => CampanhaEnvio::ESTADO_FALHOU,
+            'erro' => 'Falha anterior',
+        ]);
+    }
+
+    protected function pedirReenvio(): void
+    {
+        $request = Request::create('/', 'POST');
+        $request->setUserResolver(fn () => $this->admin);
+
+        app(CampanhaController::class)->enviarAgora($request, $this->campanha->id);
+    }
+
+    protected function executar(?string $token): void
+    {
+        (new DispararCampanhaJob($this->campanha->id, $token))->handle(
+            app(TenantManager::class),
+            app(CrmService::class),
+            app(NotificadorClienteService::class),
+            app(PortalLinkService::class),
+        );
+    }
+
+    protected function tokenDoJob(DispararCampanhaJob $job): ?string
+    {
+        $propriedade = new ReflectionProperty($job, 'reenvioToken');
+
+        return $propriedade->getValue($job);
+    }
+
+    /** O token do pedido aberto, tal como o job despachado o recebeu. */
+    protected function tokenDespachado(): string
+    {
+        $token = null;
+        Queue::assertPushed(DispararCampanhaJob::class, function (DispararCampanhaJob $job) use (&$token): bool {
+            $token = $this->tokenDoJob($job);
+
+            return true;
+        });
+
+        return (string) $token;
+    }
+
+    /** Simula o fornecedor a recusar definitivamente a notificação criada pelo reenvio. */
+    protected function falharOutboxDoEnvio(): void
+    {
+        $envio = $this->envio->fresh();
+        NotificationOutbox::withoutGlobalScopes()
+            ->whereKey($envio->notification_outbox_id)
+            ->update(['status' => NotificationOutbox::STATUS_DEAD_LETTER, 'last_error' => 'Recusada']);
+    }
+
+    public function test_dois_pedidos_simultaneos_aceitam_apenas_o_primeiro(): void
+    {
+        Queue::fake();
+
+        $this->pedirReenvio();
+
+        try {
+            $this->pedirReenvio();
+            $this->fail('O segundo pedido tinha de ser recusado enquanto o primeiro está aberto.');
+        } catch (HttpException $e) {
+            $this->assertSame(409, $e->getStatusCode());
+        }
+
+        Queue::assertPushed(DispararCampanhaJob::class, 1);
+        $this->assertSame(1, CampanhaReenvio::query()->where('campanha_id', $this->campanha->id)->count());
+        $this->assertTrue(CampanhaReenvio::query()->firstOrFail()->aberto);
+    }
+
+    public function test_a_base_de_dados_impede_dois_pedidos_abertos_na_mesma_campanha(): void
+    {
+        $dados = [
+            'empresa_id' => $this->empresa->id,
+            'campanha_id' => $this->campanha->id,
+            'estado' => CampanhaReenvio::ACEITE,
+            'aberto' => true,
+            'solicitado_em' => now(),
+        ];
+
+        CampanhaReenvio::create($dados + ['token' => '11111111-1111-4111-8111-111111111111']);
+
+        $this->expectException(UniqueConstraintViolationException::class);
+
+        CampanhaReenvio::create($dados + ['token' => '22222222-2222-4222-8222-222222222222']);
+    }
+
+    public function test_pedido_aceite_reenvia_uma_vez_e_fica_no_historico(): void
+    {
+        Queue::fake();
+        $this->pedirReenvio();
+        $token = $this->tokenDespachado();
+
+        $this->executar($token);
+
+        // Sem tentativa anterior na Outbox, o reenvio usa a tentativa 1.
+        $envio = $this->envio->fresh();
+        $this->assertSame(1, (int) $envio->tentativa);
+        $this->assertNotNull($envio->notification_outbox_id);
+        $this->assertContains($envio->estado, [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE]);
+
+        $pedido = CampanhaReenvio::query()->where('token', $token)->firstOrFail();
+        $this->assertSame(CampanhaReenvio::CONCLUIDO, $pedido->estado);
+        $this->assertNull($pedido->aberto);
+        $this->assertNotNull($pedido->iniciado_em);
+        $this->assertNotNull($pedido->concluido_em);
+        $this->assertSame($this->admin->id, $pedido->solicitado_por_id);
+    }
+
+    public function test_job_antigo_ou_duplicado_nunca_faz_um_segundo_reenvio(): void
+    {
+        Queue::fake();
+        $this->pedirReenvio();
+        $token = $this->tokenDespachado();
+
+        $this->executar($token);
+        $this->falharOutboxDoEnvio();
+
+        // A campanha volta a ficar concluída com falhas quando reconciliada.
+        $this->executar(null);
+        $this->assertSame('concluida_com_falhas', $this->campanha->fresh()->estado);
+        $outboxDepoisDoPrimeiro = $this->envio->fresh()->notification_outbox_id;
+        $tentativasDepoisDoPrimeiro = (int) $this->envio->fresh()->tentativa;
+
+        // Mesmo token, executado outra vez (job duplicado/antigo): nada acontece.
+        $this->executar($token);
+
+        $envio = $this->envio->fresh();
+        $this->assertSame($tentativasDepoisDoPrimeiro, (int) $envio->tentativa);
+        $this->assertSame($outboxDepoisDoPrimeiro, $envio->notification_outbox_id);
+        $this->assertSame(1, NotificationOutbox::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());
+    }
+
+    public function test_job_sem_token_ou_com_token_inexistente_nao_reenvia_campanha_concluida(): void
+    {
+        $this->executar(null);
+        $this->executar('99999999-9999-4999-8999-999999999999');
+
+        $envio = $this->envio->fresh();
+        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envio->estado);
+        $this->assertSame(1, (int) $envio->tentativa);
+        $this->assertNull($envio->notification_outbox_id);
+        $this->assertSame(0, NotificationOutbox::withoutGlobalScopes()->count());
+    }
+
+    public function test_novo_pedido_depois_de_concluir_e_aceite_e_o_historico_acumula(): void
+    {
+        Queue::fake();
+        $this->pedirReenvio();
+        $this->executar($this->tokenDespachado());
+        $this->falharOutboxDoEnvio();
+        $this->executar(null);
+
+        $this->pedirReenvio();
+
+        $this->assertSame(2, CampanhaReenvio::query()->where('campanha_id', $this->campanha->id)->count());
+        $this->assertSame(1, CampanhaReenvio::query()->where('aberto', true)->count());
+
+        // O segundo pedido reenvia com uma nova chave idempotente (tentativa 2).
+        $this->executar($this->tokenDespachado());
+
+        $this->assertSame(2, (int) $this->envio->fresh()->tentativa);
+        $this->assertSame(2, NotificationOutbox::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());
+        $this->assertSame(0, CampanhaReenvio::query()->where('aberto', true)->count());
+    }
+
+    public function test_pedido_aberto_e_abandonado_expira_e_deixa_pedir_de_novo(): void
+    {
+        Queue::fake();
+        $this->pedirReenvio();
+
+        CampanhaReenvio::query()->update([
+            'solicitado_em' => now()->subMinutes(CampanhaReenvio::VALIDADE_MINUTOS + 5),
+        ]);
+
+        $this->pedirReenvio();
+
+        $this->assertSame(1, CampanhaReenvio::query()->where('estado', CampanhaReenvio::EXPIRADO)->count());
+        $this->assertSame(1, CampanhaReenvio::query()->where('aberto', true)->count());
+    }
+
+    public function test_pedido_expirado_nao_pode_ser_executado_por_um_job_atrasado(): void
+    {
+        Queue::fake();
+        $this->pedirReenvio();
+        $token = $this->tokenDespachado();
+
+        CampanhaReenvio::query()->update([
+            'solicitado_em' => now()->subMinutes(CampanhaReenvio::VALIDADE_MINUTOS + 5),
+        ]);
+        $this->pedirReenvio(); // expira o antigo e abre um novo
+
+        $this->executar($token);
+
+        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $this->envio->fresh()->estado);
+        $this->assertNull($this->envio->fresh()->notification_outbox_id);
+    }
+
+    public function test_falha_definitiva_do_job_fecha_o_pedido_para_nao_bloquear_novos_reenvios(): void
+    {
+        Queue::fake();
+        $this->pedirReenvio();
+        $token = $this->tokenDespachado();
+
+        (new DispararCampanhaJob($this->campanha->id, $token))->failed(new RuntimeException('Falha de teste'));
+
+        $pedido = CampanhaReenvio::query()->where('token', $token)->firstOrFail();
+        $this->assertSame(CampanhaReenvio::FALHADO, $pedido->estado);
+        $this->assertNull($pedido->aberto);
+        $this->assertSame('Falha de teste', $pedido->motivo);
+
+        $this->pedirReenvio();
+        $this->assertSame(1, CampanhaReenvio::query()->where('aberto', true)->count());
+    }
+
+    public function test_campanha_ocupada_por_outro_job_fecha_o_pedido_como_ignorado(): void
+    {
+        Queue::fake();
+        $this->pedirReenvio();
+        $token = $this->tokenDespachado();
+
+        $lock = Cache::lock('atelier:campanha:'.$this->campanha->id, 60);
+        $this->assertTrue($lock->get());
+
+        try {
+            $this->executar($token);
+        } finally {
+            $lock->release();
+        }
+
+        $pedido = CampanhaReenvio::query()->where('token', $token)->firstOrFail();
+        $this->assertSame(CampanhaReenvio::IGNORADO, $pedido->estado);
+        $this->assertNull($pedido->aberto);
+        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $this->envio->fresh()->estado);
+
+        // O pedido não fica a bloquear: o utilizador pode voltar a pedir.
+        $this->pedirReenvio();
+        $this->assertSame(1, CampanhaReenvio::query()->where('aberto', true)->count());
+    }
+
+    public function test_campanha_sem_envios_falhados_nao_aceita_reenvio(): void
+    {
+        Queue::fake();
+        $this->envio->update(['estado' => CampanhaEnvio::ESTADO_ENVIADO]);
+
+        try {
+            $this->pedirReenvio();
+            $this->fail('Sem envios falhados não há nada para repetir.');
+        } catch (HttpException $e) {
+            $this->assertSame(422, $e->getStatusCode());
+        }
+
+        Queue::assertNothingPushed();
+        $this->assertSame(0, CampanhaReenvio::query()->count());
+    }
+}
diff --git a/Modules/Atelier/tests/Feature/ReenvioCampanhaHttpTest.php b/Modules/Atelier/tests/Feature/ReenvioCampanhaHttpTest.php
new file mode 100644
index 0000000..4981e4e
--- /dev/null
+++ b/Modules/Atelier/tests/Feature/ReenvioCampanhaHttpTest.php
@@ -0,0 +1,59 @@
+<?php
+
+namespace Modules\Atelier\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Queue;
+use Modules\Atelier\Jobs\DispararCampanhaJob;
+use Modules\Atelier\Models\Campanha;
+use Modules\Atelier\Models\CampanhaEnvio;
+use Modules\Atelier\Models\CampanhaReenvio;
+use Modules\Core\Services\RegistoEmpresaService;
+use Modules\Core\Services\TenantManager;
+use Modules\Faturacao\Models\Cliente;
+use Tests\TestCase;
+
+/** O botão "Reenviar falhados" pela rota real (auth + tenant + subscrição + serviço + papel). */
+class ReenvioCampanhaHttpTest extends TestCase
+{
+    use RefreshDatabase;
+
+    public function test_clique_duplo_na_rota_real_aceita_um_pedido_e_recusa_o_segundo(): void
+    {
+        $this->withoutVite();
+        Queue::fake();
+
+        $admin = app(RegistoEmpresaService::class)->registar(
+            ['nome_comercial' => 'Atelier HTTP', 'nif' => '400000502'],
+            ['name' => 'Admin HTTP', 'email' => 'admin.http@example.test', 'password' => 'Senha-segura-123'],
+            ['atelier'],
+        );
+        app(TenantManager::class)->set((int) $admin->empresa_id);
+
+        $cliente = Cliente::create(['empresa_id' => $admin->empresa_id, 'nome' => 'Cliente HTTP', 'telefone' => '923456789']);
+        $campanha = Campanha::create([
+            'empresa_id' => $admin->empresa_id,
+            'nome' => 'Campanha HTTP',
+            'canal' => 'whatsapp',
+            'segmento' => 'todos',
+            'mensagem' => 'Mensagem de teste',
+            'estado' => 'concluida_com_falhas',
+            'enviada_em' => now()->subHour(),
+        ]);
+        CampanhaEnvio::create([
+            'campanha_id' => $campanha->id,
+            'cliente_id' => $cliente->id,
+            'canal' => 'whatsapp',
+            'estado' => CampanhaEnvio::ESTADO_FALHOU,
+        ]);
+
+        $rota = route('atelier.campanhas.enviar-agora', $campanha->id);
+
+        $this->actingAs($admin)->post($rota)->assertRedirect()->assertSessionHas('sucesso');
+        $this->actingAs($admin)->post($rota)->assertStatus(409);
+
+        app(TenantManager::class)->set((int) $admin->empresa_id);
+        Queue::assertPushed(DispararCampanhaJob::class, 1);
+        $this->assertSame(1, CampanhaReenvio::query()->where('campanha_id', $campanha->id)->count());
+    }
+}
PATCH_02_REENVIO

aplicar() {
  local ficheiro="$1" mensagem="$2"
  # Idempotência: o patch 2 altera ficheiros do patch 1, por isso não se pode
  # testar com "apply -R". Se o commit já existe depois da base, salta.
  if git log --format=%s "$COMMIT_BASE"..HEAD | grep -Fxq -- "$mensagem"; then
    info "já aplicado, a saltar: $mensagem"
    return 0
  fi
  git apply --check "$ficheiro" 2>/dev/null \
    || erro "o patch não aplica limpo (a branch mudou?): $mensagem"
  git apply "$ficheiro"
  git add -A
  git commit -q -m "$mensagem"
  info "commit: $mensagem"
}

aplicar "$TMP/01-estilo.patch"  "style: aplicar Pint v1.32.1 (preset Laravel) nos 3 ficheiros em falha"
aplicar "$TMP/02-reenvio.patch" "fix(atelier): proteger o reenvio de campanhas na aceitação do pedido"

# --- 2. Validação local (se as dependências estiverem instaladas) --------------
export XDEBUG_MODE=off
if [[ -x vendor/bin/pint ]]; then
  info "Pint nos ficheiros PHP alterados pela PR"
  git fetch -q origin main 2>/dev/null || true
  BASE_REF="origin/main"; git rev-parse -q --verify "$BASE_REF" >/dev/null || BASE_REF="$COMMIT_BASE"
  mapfile -t FICHEIROS < <(git diff --name-only --diff-filter=d "$BASE_REF"...HEAD -- '*.php')
  if ((${#FICHEIROS[@]})); then
    vendor/bin/pint --test "${FICHEIROS[@]}" || erro "o Pint ainda reprova — não faças push."
  fi
else
  echo "AVISO: vendor/bin/pint não existe (corre 'composer install'); validação de estilo saltada."
fi

if [[ -f vendor/autoload.php ]]; then
  info "Testes do reenvio de campanhas + módulo Atelier"
  php artisan test Modules/Atelier || erro "testes do Atelier falharam — não faças push."
  info "Suite completa (como no CI, sem o grupo auditoria)"
  php artisan test --exclude-group=auditoria || erro "a suite falhou — não faças push."
else
  echo "AVISO: vendor/ não existe; testes saltados."
fi

# --- 3. Push (opcional) ------------------------------------------------------
if ((PUSH)); then
  info "git push origin $RAMO_ESPERADO"
  git push origin "$RAMO_ESPERADO"
  info "Feito. Acompanha o CI da PR #33 (qualidade + testes-mysql)."
else
  echo
  info "Concluído localmente. Quando quiseres enviar:  git push origin $RAMO_ESPERADO"
fi
