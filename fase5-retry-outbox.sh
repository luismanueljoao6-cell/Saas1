#!/usr/bin/env bash
# PR #33 — Fase 5: política de retry por tipo de erro na Outbox (Core)
# Correr na raiz do repositório, na branch feat/faturacao-fiscal-core.
set -euo pipefail

[ "$(git rev-parse --abbrev-ref HEAD)" = "feat/faturacao-fiscal-core" ] || { echo "Muda para feat/faturacao-fiscal-core"; exit 1; }
git pull --ff-only
[ -z "$(git status --porcelain)" ] || { echo "Working tree com alterações; faz commit/stash primeiro."; exit 1; }

cat > /tmp/fase5.patch <<'PATCH_EOF'
diff --git a/Modules/Core/app/Notifications/NotificationProcessor.php b/Modules/Core/app/Notifications/NotificationProcessor.php
index 3a80872..a1f6f49 100644
--- a/Modules/Core/app/Notifications/NotificationProcessor.php
+++ b/Modules/Core/app/Notifications/NotificationProcessor.php
@@ -3,6 +3,7 @@
 namespace Modules\Core\Notifications;
 
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Str;
 use Modules\Core\Events\NotificationStateChanged;
 use Modules\Core\Jobs\ProcessNotificationJob;
@@ -10,6 +11,7 @@
 use Modules\Core\Models\NotificationOutbox;
 use Modules\Core\Notifications\Exceptions\ResultadoIndeterminadoException;
 use Modules\Core\Services\TenantManager;
+use InvalidArgumentException;
 use Throwable;
 
 class NotificationProcessor
@@ -133,6 +135,11 @@ private function processWithinTenant(int $notificationId, int $empresaId): void
             // O pedido pode ter chegado ao fornecedor: nunca se repete sozinho.
             $this->recordUnknownResult($claim, $empresaId, $exception);
 
+            return;
+        } catch (InvalidArgumentException $exception) {
+            // Payload inválido não se corrige a repetir: falha definitiva.
+            $this->recordFailure($claim, $empresaId, $exception->getMessage(), 'invalid_payload');
+
             return;
         } catch (Throwable $exception) {
             $this->recordFailure($claim, $empresaId, $exception->getMessage());
@@ -241,9 +248,9 @@ private function recordUnknownResult(array $claim, int $empresaId, ResultadoInde
         });
     }
 
-    private function recordFailure(array $claim, int $empresaId, string $message): void
+    private function recordFailure(array $claim, int $empresaId, string $message, ?string $errorCode = null): void
     {
-        DB::transaction(function () use ($claim, $empresaId, $message): void {
+        DB::transaction(function () use ($claim, $empresaId, $message, $errorCode): void {
             $notification = NotificationOutbox::withoutGlobalScopes()
                 ->where('empresa_id', $empresaId)
                 ->whereKey($claim['notification']->id)
@@ -260,7 +267,7 @@ private function recordFailure(array $claim, int $empresaId, string $message): v
                 ->whereKey($claim['attempt_id'])
                 ->first();
 
-            $this->scheduleRetry($notification, $attempt, $message);
+            $this->scheduleRetry($notification, $attempt, $message, $errorCode);
 
             $notification->refresh();
             if (in_array($notification->status, [NotificationOutbox::STATUS_FAILED, NotificationOutbox::STATUS_DEAD_LETTER], true)) {
@@ -296,10 +303,12 @@ private function scheduleRetry(
         ?int $responseCode = null,
         array $metadata = [],
     ): void {
-        // Bloqueios legais ou de configuração não são resolvidos por retry.
-        $permanent = $errorCode === 'apd_transfer_not_authorized';
+        // Credenciais, bloqueios legais/configuração e destinatários inválidos não
+        // se resolvem por retry; limites do fornecedor esperam mais.
+        $classe = NotificationRetryPolicy::classify($errorCode, $responseCode);
+        $permanent = NotificationRetryPolicy::semRetry($classe);
         $terminal = $permanent || $notification->attempts >= $notification->max_attempts;
-        $delay = min(3600, 30 * (2 ** max(0, $notification->attempts - 1)));
+        $delay = NotificationRetryPolicy::atrasoSegundos($classe, (int) $notification->attempts, $metadata);
 
         $notification->forceFill([
             'status' => $permanent
@@ -321,6 +330,18 @@ private function scheduleRetry(
             'finished_at' => now(),
         ])->save();
 
+        if ($classe === NotificationRetryPolicy::BLOCKED) {
+            // Sem destinatário nem payload: só o necessário para um alerta operacional.
+            Log::critical('Outbox: envio bloqueado por credenciais ou configuração; é preciso intervenção, o retry não resolve.', [
+                'notification_id' => $notification->id,
+                'empresa_id' => $notification->empresa_id,
+                'channel' => $notification->channel,
+                'provider' => $notification->provider,
+                'error_code' => $errorCode,
+                'response_code' => $responseCode,
+            ]);
+        }
+
         if (! $terminal) {
             ProcessNotificationJob::dispatch($notification->id, (int) $notification->empresa_id)
                 ->delay(now()->addSeconds($delay))
diff --git a/Modules/Core/app/Notifications/NotificationRetryPolicy.php b/Modules/Core/app/Notifications/NotificationRetryPolicy.php
new file mode 100644
index 0000000..1443228
--- /dev/null
+++ b/Modules/Core/app/Notifications/NotificationRetryPolicy.php
@@ -0,0 +1,96 @@
+<?php
+
+namespace Modules\Core\Notifications;
+
+/**
+ * Classifica uma recusa explícita do fornecedor (ou uma falha antes do envio) e
+ * decide o que fazer a seguir. Não basta limitar o número de tentativas: um
+ * destinatário inválido ou credenciais erradas nunca se resolvem por retry.
+ *
+ * | Situação                        | Classe       | Tratamento                       |
+ * |---------------------------------|--------------|----------------------------------|
+ * | Credenciais inválidas / 401/403 | BLOCKED      | failed + alerta, sem retry       |
+ * | Transferência APD não autorizada| BLOCKED      | failed + alerta, sem retry       |
+ * | Destinatário/payload inválido   | PERMANENT    | failed, sem retry                |
+ * | Limite do fornecedor / 429      | RATE_LIMITED | retry com espera mínima/retry_after |
+ * | Erro temporário / 5xx / outros  | RETRY        | backoff exponencial              |
+ *
+ * Timeout e resposta indeterminada não passam por aqui: o canal lança
+ * ResultadoIndeterminadoException e a notificação fica em «unknown_result».
+ * Uma aceitação confirmada nunca é reenviada (ver NotificationProcessor).
+ */
+final class NotificationRetryPolicy
+{
+    public const RETRY = 'retry';
+
+    public const RATE_LIMITED = 'rate_limited';
+
+    public const PERMANENT = 'permanent';
+
+    public const BLOCKED = 'blocked';
+
+    private const BLOCKED_CODES = [
+        'invalid_credentials',
+        'provider_auth_failed',
+        'apd_transfer_not_authorized',
+    ];
+
+    private const PERMANENT_CODES = [
+        'invalid_recipient',
+        'invalid_payload',
+    ];
+
+    private const RATE_LIMITED_CODES = [
+        'rate_limited',
+        'provider_rate_limit',
+    ];
+
+    public static function classify(?string $errorCode, ?int $responseCode = null): string
+    {
+        if ($errorCode !== null) {
+            if (in_array($errorCode, self::BLOCKED_CODES, true)) {
+                return self::BLOCKED;
+            }
+
+            if (in_array($errorCode, self::PERMANENT_CODES, true)) {
+                return self::PERMANENT;
+            }
+
+            if (in_array($errorCode, self::RATE_LIMITED_CODES, true)) {
+                return self::RATE_LIMITED;
+            }
+        }
+
+        return match (true) {
+            in_array($responseCode, [401, 403], true) => self::BLOCKED,
+            $responseCode === 429 => self::RATE_LIMITED,
+            in_array($responseCode, [400, 404, 410, 422], true) => self::PERMANENT,
+            default => self::RETRY,
+        };
+    }
+
+    /** Falha que não se resolve repetindo o envio. */
+    public static function semRetry(string $classe): bool
+    {
+        return in_array($classe, [self::PERMANENT, self::BLOCKED], true);
+    }
+
+    /**
+     * Espera até à próxima tentativa, em segundos. Limite do fornecedor respeita
+     * o retry_after indicado (se existir) e nunca espera menos de 60 s.
+     *
+     * @param  array<string, mixed>  $metadata
+     */
+    public static function atrasoSegundos(string $classe, int $tentativas, array $metadata = []): int
+    {
+        $backoff = min(3600, 30 * (2 ** max(0, $tentativas - 1)));
+
+        if ($classe !== self::RATE_LIMITED) {
+            return $backoff;
+        }
+
+        $retryAfter = max(0, (int) ($metadata['retry_after'] ?? 0));
+
+        return min(3600, max($backoff, 60, $retryAfter));
+    }
+}
diff --git a/Modules/Core/tests/Feature/NotificationProcessorTest.php b/Modules/Core/tests/Feature/NotificationProcessorTest.php
index 9d54872..157cea2 100644
--- a/Modules/Core/tests/Feature/NotificationProcessorTest.php
+++ b/Modules/Core/tests/Feature/NotificationProcessorTest.php
@@ -390,6 +390,77 @@ public function test_dispatcher_recusa_empresa_diferente_do_tenant_e_isola_chave
 
     // ---------------------------------------------------------------------
     // Preparação
+    // ---------------------------------------------------------------------
+    // Política de retry: nem todas as falhas se repetem
+    // ---------------------------------------------------------------------
+
+    public function test_destinatario_invalido_falha_de_vez_sem_retry(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-destinatario-invalido');
+        $jobsAntes = Queue::pushed(ProcessNotificationJob::class)->count();
+        $this->canal->aoEnviar = fn (): NotificationSendResult => NotificationSendResult::rejected('teste', 'Número inválido.', 'invalid_recipient', 400);
+
+        $this->processar($notificacao);
+
+        $notificacao->refresh();
+        $this->assertSame(NotificationOutbox::STATUS_FAILED, $notificacao->status);
+        $this->assertSame(1, $this->canal->envios);
+        $this->assertCount($jobsAntes, Queue::pushed(ProcessNotificationJob::class), 'Falha definitiva não agenda retry.');
+    }
+
+    public function test_credenciais_invalidas_bloqueiam_e_geram_alerta_sem_expor_o_destinatario(): void
+    {
+        Log::spy();
+        $notificacao = $this->novaNotificacao('chave-credenciais');
+        $jobsAntes = Queue::pushed(ProcessNotificationJob::class)->count();
+        $this->canal->aoEnviar = fn (): NotificationSendResult => NotificationSendResult::rejected('teste', 'Unauthorized', null, 401);
+
+        $this->processar($notificacao);
+
+        $this->assertSame(NotificationOutbox::STATUS_FAILED, $notificacao->fresh()->status);
+        $this->assertCount($jobsAntes, Queue::pushed(ProcessNotificationJob::class));
+        Log::shouldHaveReceived('critical')->once()->withArgs(
+            fn (string $mensagem, array $contexto): bool => ! str_contains(json_encode($contexto), '+244923000000')
+                && ($contexto['response_code'] ?? null) === 401
+        );
+    }
+
+    public function test_limite_do_fornecedor_agenda_retry_respeitando_o_retry_after(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-limite');
+        $this->canal->aoEnviar = fn (): NotificationSendResult => NotificationSendResult::rejected('teste', 'Too many requests', 'rate_limited', 429, ['retry_after' => 300]);
+
+        $this->processar($notificacao);
+
+        $notificacao->refresh();
+        $this->assertSame(NotificationOutbox::STATUS_RETRY_SCHEDULED, $notificacao->status);
+        $this->assertTrue($notificacao->available_at->gte(now()->addSeconds(295)), 'Deve esperar pelo menos o retry_after.');
+    }
+
+    public function test_payload_invalido_lancado_pelo_canal_nao_e_repetido(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-payload-invalido');
+        $jobsAntes = Queue::pushed(ProcessNotificationJob::class)->count();
+        $this->canal->aoEnviar = function (): NotificationSendResult {
+            throw new \InvalidArgumentException('Payload.message é obrigatório.');
+        };
+
+        $this->processar($notificacao);
+
+        $this->assertSame(NotificationOutbox::STATUS_FAILED, $notificacao->fresh()->status);
+        $this->assertCount($jobsAntes, Queue::pushed(ProcessNotificationJob::class));
+    }
+
+    public function test_erro_temporario_continua_com_backoff_e_dead_letter_no_limite(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-temporario', 1);
+        $this->canal->aoEnviar = fn (): NotificationSendResult => NotificationSendResult::rejected('teste', 'Indisponível', null, 503);
+
+        $this->processar($notificacao);
+
+        $this->assertSame(NotificationOutbox::STATUS_DEAD_LETTER, $notificacao->fresh()->status);
+    }
+
     // ---------------------------------------------------------------------
 
     private function novaNotificacao(string $chave, int $maxAttempts = 5): NotificationOutbox
diff --git a/Modules/Core/tests/Unit/NotificationRetryPolicyTest.php b/Modules/Core/tests/Unit/NotificationRetryPolicyTest.php
new file mode 100644
index 0000000..3b17184
--- /dev/null
+++ b/Modules/Core/tests/Unit/NotificationRetryPolicyTest.php
@@ -0,0 +1,49 @@
+<?php
+
+namespace Modules\Core\Tests\Unit;
+
+use Modules\Core\Notifications\NotificationRetryPolicy;
+use PHPUnit\Framework\TestCase;
+
+class NotificationRetryPolicyTest extends TestCase
+{
+    public function test_classifica_cada_situacao_da_tabela_de_tratamento(): void
+    {
+        $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify('invalid_credentials'));
+        $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify(null, 401));
+        $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify(null, 403));
+        $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify('apd_transfer_not_authorized'));
+        $this->assertSame(NotificationRetryPolicy::PERMANENT, NotificationRetryPolicy::classify('invalid_recipient'));
+        $this->assertSame(NotificationRetryPolicy::PERMANENT, NotificationRetryPolicy::classify('invalid_payload'));
+        $this->assertSame(NotificationRetryPolicy::PERMANENT, NotificationRetryPolicy::classify(null, 422));
+        $this->assertSame(NotificationRetryPolicy::RATE_LIMITED, NotificationRetryPolicy::classify('rate_limited'));
+        $this->assertSame(NotificationRetryPolicy::RATE_LIMITED, NotificationRetryPolicy::classify(null, 429));
+        $this->assertSame(NotificationRetryPolicy::RETRY, NotificationRetryPolicy::classify(null, 503));
+        $this->assertSame(NotificationRetryPolicy::RETRY, NotificationRetryPolicy::classify('channel_rejected'));
+        $this->assertSame(NotificationRetryPolicy::RETRY, NotificationRetryPolicy::classify(null));
+    }
+
+    public function test_o_codigo_de_erro_tem_prioridade_sobre_o_estado_http(): void
+    {
+        $this->assertSame(NotificationRetryPolicy::PERMANENT, NotificationRetryPolicy::classify('invalid_recipient', 503));
+    }
+
+    public function test_so_permanentes_e_bloqueios_ficam_sem_retry(): void
+    {
+        $this->assertTrue(NotificationRetryPolicy::semRetry(NotificationRetryPolicy::PERMANENT));
+        $this->assertTrue(NotificationRetryPolicy::semRetry(NotificationRetryPolicy::BLOCKED));
+        $this->assertFalse(NotificationRetryPolicy::semRetry(NotificationRetryPolicy::RETRY));
+        $this->assertFalse(NotificationRetryPolicy::semRetry(NotificationRetryPolicy::RATE_LIMITED));
+    }
+
+    public function test_backoff_exponencial_com_limite_e_espera_minima_no_limite_do_fornecedor(): void
+    {
+        $this->assertSame(30, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RETRY, 1));
+        $this->assertSame(120, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RETRY, 3));
+        $this->assertSame(3600, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RETRY, 20));
+
+        $this->assertSame(60, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RATE_LIMITED, 1));
+        $this->assertSame(300, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RATE_LIMITED, 1, ['retry_after' => 300]));
+        $this->assertSame(3600, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RATE_LIMITED, 1, ['retry_after' => 99999]));
+    }
+}
PATCH_EOF

git apply --check /tmp/fase5.patch
git apply /tmp/fase5.patch

php artisan test Modules/Core/tests/Unit/NotificationRetryPolicyTest.php Modules/Core/tests/Feature/NotificationProcessorTest.php Modules/Core/tests/Feature/OutboxResultadoDesconhecidoTest.php Modules/Core/tests/Feature/OutboxRecuperacaoAgendadaTest.php

git add -A
git commit -m "fix(core): política de retry por tipo de erro na Outbox (fase 5 da PR #33)"
echo "Fase 5 aplicada. Faz push quando os testes completos passarem."
