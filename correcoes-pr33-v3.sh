#!/usr/bin/env bash
#
# correcoes-pr33-v3.sh — auditoria da PR #33 (feat/faturacao-fiscal-core)
#
# Ponto de partida: head 35a92c9. Cada ponto da auditoria foi confirmado no
# código antes de ser corrigido; o que dependia de uma especificação oficial
# NÃO foi tocado (ver o fim deste cabeçalho).
#
# O que faz — 3 commits, cada um com os seus testes:
#
#  1. Testes de isolamento multi-tenant (Estúdio, portal por token, gateway):
#     o administrador de uma empresa recebe 404 em todas as rotas com ID de
#     outra e a base de dados fica intacta; as listagens não vazam dados; o
#     token do portal não alcança versões de outro artista nem de outra
#     empresa. (Passaram à primeira: o isolamento já estava correto; os testes
#     provam-no e falham se alguém o estragar — foi verificado por mutação.)
#
#  2. Outbox — defeitos reais encontrados ao testar o NotificationProcessor:
#       a) uma notificação «accepted» não é terminal e podia ser REENVIADA por
#          um segundo job (o job original + o reagendado pelo recuperador) ou
#          até convertida em dead letter no limite de tentativas;
#       b) um listener de outro módulo a falhar fazia rollback do estado já
#          aceite pelo fornecedor, o recuperador reenviava a mensagem;
#       c) tentativas perdidas ficavam «a processar» para sempre; passam a
#          «abandoned» (resultado desconhecido);
#       d) o lease de 2 min passa a constante (LEASE_SEGUNDOS) com teste de
#          coerência: pedido ao fornecedor < timeout do job < lease;
#       e) o recuperador regista em log abandonadas e dead letter (24 h).
#     Já estava correto (agora com testes): um worker antigo nunca sobrepõe o
#     resultado de uma tentativa recuperada.
#
#  3. Dinheiro — sem float na fronteira:
#       - o contrato do FaturacaoGateway deixa de aceitar float em valores;
#       - Atelier e Estúdio passam decimais como texto (sem «(float)»);
#       - Dinheiro::pontosBase() lê percentagens sem multiplicar floats;
#       - PedidoMaterial::valor_total em aritmética exata: o float antigo
#         errava 1 cêntimo em ~0,4 % dos casos (ex.: 47,25 × 72 060,70).
#     + testes do gateway: totais exatos, texto vs float, pagamentos parciais,
#       nota de crédito e recusa de clientes/faturas de outra empresa.
#
# NÃO feito (precisa de especificação oficial; não se inventa):
#   P0 — adiantamentos/recibos (RC/RG), SAF-T contra o XSD da AGT, códigos fiscais.
#   P2 — reconciliação por identificador externo do fornecedor, PDF/impressão.
#   Concorrência entre processos reais (precisa de MySQL e vários workers).
#
# Uso, na raiz do repositório (Codespace):
#   bash correcoes-pr33-v3.sh                 # aplica, valida, faz commits e push
#   SEM_PUSH=1 bash correcoes-pr33-v3.sh      # aplica, valida e faz commits (sem push)
#   SEM_TESTES=1 bash correcoes-pr33-v3.sh    # corre só os testes novos (salta a suite completa)
#
# Seguro por desenho: recusa-se a correr fora do ramo feat/faturacao-fiscal-core
# (nunca toca na main), não faz merge, só mexe nos ficheiros listados abaixo e
# pode ser repetido sem efeitos duplicados.

set -Eeuo pipefail

RAMO="feat/faturacao-fiscal-core"
PR_NUMERO=33
PR_URL="https://github.com/luismanueljoao6-cell/Saas1/pull/33"

# Commit 1 — testes de isolamento
FICHEIROS_ISOLAMENTO=(
  "Modules/EstudioMusica/tests/Support/CriaEstudioComDados.php"
  "Modules/EstudioMusica/tests/Feature/IsolamentoEstudioTest.php"
  "Modules/EstudioMusica/tests/Feature/IsolamentoPortalEstudioTest.php"
)

# Commit 2 — Outbox
FICHEIROS_OUTBOX=(
  "Modules/Core/app/Notifications/NotificationProcessor.php"
  "Modules/Core/app/Console/Commands/RecoverNotificationOutbox.php"
  "Modules/Core/app/Models/NotificationOutbox.php"
  "Modules/Core/app/Models/NotificationAttempt.php"
  "Modules/Core/tests/Feature/NotificationProcessorTest.php"
)

# Commit 3 — dinheiro
FICHEIROS_DINHEIRO=(
  "Modules/Faturacao/app/Support/Dinheiro.php"
  "Modules/Faturacao/app/Contracts/FaturacaoGateway.php"
  "Modules/Faturacao/app/Services/FaturacaoGatewayService.php"
  "Modules/Atelier/app/Http/Controllers/Admin/PedidoController.php"
  "Modules/Atelier/app/Models/PedidoMaterial.php"
  "Modules/Atelier/app/Services/PedidoFaturacaoService.php"
  "Modules/EstudioMusica/app/Http/Controllers/ProjetoMusicalController.php"
  "Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php"
  "Modules/Faturacao/tests/Feature/GatewayFronteiraTest.php"
  "Modules/Atelier/tests/Feature/PedidoMaterialValorExatoTest.php"
)

TESTES_NOVOS=(
  "Modules/EstudioMusica/tests/Feature/IsolamentoEstudioTest.php"
  "Modules/EstudioMusica/tests/Feature/IsolamentoPortalEstudioTest.php"
  "Modules/Core/tests/Feature/NotificationProcessorTest.php"
  "Modules/Faturacao/tests/Feature/GatewayFronteiraTest.php"
  "Modules/Atelier/tests/Feature/PedidoMaterialValorExatoTest.php"
)

TODOS_OS_FICHEIROS=("${FICHEIROS_ISOLAMENTO[@]}" "${FICHEIROS_OUTBOX[@]}" "${FICHEIROS_DINHEIRO[@]}")

if [[ -t 1 ]]; then
  VERDE=$'\e[32m'; AMARELO=$'\e[33m'; VERMELHO=$'\e[31m'; NEGRITO=$'\e[1m'; FIM=$'\e[0m'
else
  VERDE=""; AMARELO=""; VERMELHO=""; NEGRITO=""; FIM=""
fi

passo() { printf '\n%s▶ %s%s\n' "$NEGRITO" "$1" "$FIM"; }
ok()    { printf '  %s✔%s %s\n' "$VERDE" "$FIM" "$1"; }
aviso() { printf '  %s!%s %s\n' "$AMARELO" "$FIM" "$1"; }
falha() { printf '\n%s✘ %s%s\n' "$VERMELHO" "$1" "$FIM" >&2; exit 1; }

trap 'printf "\n%s✘ O script parou na linha %s. Se ainda não viste «Push concluído», nada foi enviado para o GitHub.%s\n" "$VERMELHO" "$LINENO" "$FIM" >&2' ERR

PATCH="$(mktemp)"
trap 'rm -f "$PATCH"' EXIT

patch_correcoes() {
cat <<'__FIM_DO_PATCH__'
diff --git a/Modules/Atelier/app/Http/Controllers/Admin/PedidoController.php b/Modules/Atelier/app/Http/Controllers/Admin/PedidoController.php
index 2258eb2..b47c1e0 100644
--- a/Modules/Atelier/app/Http/Controllers/Admin/PedidoController.php
+++ b/Modules/Atelier/app/Http/Controllers/Admin/PedidoController.php
@@ -156,7 +156,7 @@ public function registarSinal(RegistarSinalRequest $request, int $pedido): Redir
         $this->garantirQuePodeEmitirFaturas($pedido->empresa);
 
         try {
-            $this->faturacaoService->registarSinal($pedido, (float) $request->validated('valor'), $request->validated('meio_pagamento'));
+            $this->faturacaoService->registarSinal($pedido, $request->validated('valor'), $request->validated('meio_pagamento'));
         } catch (PedidoException $e) {
             return back()->with('erro', $e->getMessage());
         } catch (Throwable $e) {
diff --git a/Modules/Atelier/app/Models/PedidoMaterial.php b/Modules/Atelier/app/Models/PedidoMaterial.php
index 4da8826..54445b3 100644
--- a/Modules/Atelier/app/Models/PedidoMaterial.php
+++ b/Modules/Atelier/app/Models/PedidoMaterial.php
@@ -4,6 +4,7 @@
 
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
+use Modules\Faturacao\Support\Dinheiro;
 
 class PedidoMaterial extends Model
 {
@@ -27,7 +28,12 @@ public function pedido(): BelongsTo
 
     public function calcularValores(): static
     {
-        $this->valor_total = round((float) $this->quantidade * (float) $this->valor_unitario, 2);
+        // Quantidade × valor unitário em aritmética exata (sem float); o IVA só entra na fatura.
+        $quantidade = (string) $this->quantidade;
+
+        $this->valor_total = Dinheiro::milesimos($quantidade) > 0
+            ? Dinheiro::calcularLinha($quantidade, (string) $this->valor_unitario, 0)['sem_iva']
+            : '0.00';
 
         return $this;
     }
diff --git a/Modules/Atelier/app/Services/PedidoFaturacaoService.php b/Modules/Atelier/app/Services/PedidoFaturacaoService.php
index c2ddf10..7ce4a8b 100644
--- a/Modules/Atelier/app/Services/PedidoFaturacaoService.php
+++ b/Modules/Atelier/app/Services/PedidoFaturacaoService.php
@@ -36,7 +36,7 @@ public function __construct(FaturacaoGateway $faturacao)
     /**
      * @throws PedidoException|Throwable
      */
-    public function registarSinal(Pedido $pedido, float $valor, string $meioPagamento): Recibo
+    public function registarSinal(Pedido $pedido, string|int|float $valor, string $meioPagamento): Recibo
     {
         return DB::transaction(function () use ($pedido, $valor, $meioPagamento) {
             $original = $pedido;
@@ -50,7 +50,7 @@ public function registarSinal(Pedido $pedido, float $valor, string $meioPagament
                 throw PedidoException::sinalJaRegistado();
             }
 
-            if ($valor <= 0) {
+            if (Dinheiro::centimos($valor) <= 0) {
                 throw PedidoException::valorInvalido();
             }
 
@@ -107,8 +107,8 @@ public function gerarFaturaFinalRascunho(Pedido $pedido, ?string $observacoes =
                 [
                     'descricao' => "{$pedido->tipoServicoRotulo()}: {$pedido->descricao}",
                     'quantidade' => 1,
-                    'preco_unitario' => (float) $pedido->valor_orcamento,
-                    'taxa_iva' => (float) $pedido->taxa_iva_aplicada,
+                    'preco_unitario' => (string) $pedido->valor_orcamento,
+                    'taxa_iva' => (string) $pedido->taxa_iva_aplicada,
                     'operation_type' => 'SG',
                 ],
             ];
@@ -116,9 +116,9 @@ public function gerarFaturaFinalRascunho(Pedido $pedido, ?string $observacoes =
             foreach ($pedido->materiais as $material) {
                 $linhas[] = [
                     'descricao' => $material->descricao,
-                    'quantidade' => (float) $material->quantidade,
-                    'preco_unitario' => (float) $material->valor_unitario,
-                    'taxa_iva' => (float) $pedido->taxa_iva_aplicada,
+                    'quantidade' => (string) $material->quantidade,
+                    'preco_unitario' => (string) $material->valor_unitario,
+                    'taxa_iva' => (string) $pedido->taxa_iva_aplicada,
                     'operation_type' => 'TB',
                 ];
             }
diff --git a/Modules/Atelier/tests/Feature/PedidoMaterialValorExatoTest.php b/Modules/Atelier/tests/Feature/PedidoMaterialValorExatoTest.php
new file mode 100644
index 0000000..d6678cd
--- /dev/null
+++ b/Modules/Atelier/tests/Feature/PedidoMaterialValorExatoTest.php
@@ -0,0 +1,40 @@
+<?php
+
+namespace Modules\Atelier\Tests\Feature;
+
+use Modules\Atelier\Models\PedidoMaterial;
+use PHPUnit\Framework\Attributes\DataProvider;
+use Tests\TestCase;
+
+/**
+ * O valor total de um material (quantidade × valor unitário) é guardado em
+ * aritmética exata, com arredondamento half up ao cêntimo — o mesmo da fatura.
+ * Com float, vários produtos «a meio cêntimo» saíam um cêntimo abaixo do
+ * correto (ex.: 47,25 × 72 060,70 = 3 404 868,075 saía 3 404 868,07).
+ */
+class PedidoMaterialValorExatoTest extends TestCase
+{
+    /**
+     * @return array<string, array{0: string, 1: string, 2: string}>
+     */
+    public static function produtos(): array
+    {
+        return [
+            'meio cêntimo, arredonda para cima (float dava .07)' => ['47.25', '72060.70', '3404868.08'],
+            'outro meio cêntimo (float dava .44)' => ['66.55', '78341.90', '5213653.45'],
+            'meio cêntimo pequeno' => ['1.50', '33.33', '50.00'],
+            'valor exato sem arredondamento' => ['3.00', '0.10', '0.30'],
+            'abaixo de meio cêntimo' => ['0.01', '0.01', '0.00'],
+            'preço zero (oferta)' => ['2.00', '0.00', '0.00'],
+            'quantidade zero não rebenta' => ['0.00', '10.00', '0.00'],
+        ];
+    }
+
+    #[DataProvider('produtos')]
+    public function test_valor_total_do_material_e_exato(string $quantidade, string $valorUnitario, string $esperado): void
+    {
+        $material = new PedidoMaterial(['quantidade' => $quantidade, 'valor_unitario' => $valorUnitario]);
+
+        $this->assertSame($esperado, (string) $material->calcularValores()->valor_total);
+    }
+}
diff --git a/Modules/Core/app/Console/Commands/RecoverNotificationOutbox.php b/Modules/Core/app/Console/Commands/RecoverNotificationOutbox.php
index d0c0acf..05993ec 100644
--- a/Modules/Core/app/Console/Commands/RecoverNotificationOutbox.php
+++ b/Modules/Core/app/Console/Commands/RecoverNotificationOutbox.php
@@ -4,8 +4,11 @@
 
 use Illuminate\Console\Command;
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
 use Modules\Core\Jobs\ProcessNotificationJob;
+use Modules\Core\Models\NotificationAttempt;
 use Modules\Core\Models\NotificationOutbox;
+use Modules\Core\Notifications\NotificationProcessor;
 
 class RecoverNotificationOutbox extends Command
 {
@@ -17,10 +20,11 @@ public function handle(): int
     {
         $limit = max(1, min(5000, (int) $this->option('limit')));
         $now = now();
-        $staleBefore = $now->copy()->subMinutes(2);
+        $staleBefore = $now->copy()->subSeconds(NotificationProcessor::LEASE_SEGUNDOS);
         $count = 0;
+        $abandonadas = 0;
 
-        DB::transaction(function () use ($limit, $now, $staleBefore, &$count): void {
+        DB::transaction(function () use ($limit, $now, $staleBefore, &$count, &$abandonadas): void {
             $notifications = NotificationOutbox::withoutGlobalScopes()
                 ->where(function ($query) use ($now, $staleBefore): void {
                     $query->where(function ($pending) use ($now): void {
@@ -46,6 +50,9 @@ public function handle(): int
                 // dispatch afterCommit, evitando que dois recuperadores limpem
                 // simultaneamente o mesmo lease abandonado.
                 if ($notification->status === NotificationOutbox::STATUS_PROCESSING) {
+                    NotificationAttempt::assinalarAbandonadas((int) $notification->empresa_id, (int) $notification->id);
+                    $abandonadas++;
+
                     $notification->forceFill([
                         'status' => NotificationOutbox::STATUS_RETRY_SCHEDULED,
                         'locked_at' => null,
@@ -61,7 +68,25 @@ public function handle(): int
             }
         });
 
-        $this->info("Notificações agendadas para processamento: {$count}");
+        // Monitorização mínima, para um alerta poder apoiar-se nestas linhas de log.
+        if ($abandonadas > 0) {
+            Log::warning('Outbox: leases abandonados recuperados; o resultado das tentativas perdidas é desconhecido e a mensagem pode ser reenviada.', [
+                'abandonadas' => $abandonadas,
+            ]);
+        }
+
+        $deadLetters = NotificationOutbox::withoutGlobalScopes()
+            ->where('status', NotificationOutbox::STATUS_DEAD_LETTER)
+            ->where('failed_at', '>=', $now->copy()->subDay())
+            ->count();
+
+        if ($deadLetters > 0) {
+            Log::warning('Outbox: notificações em dead letter nas últimas 24 horas precisam de atenção.', [
+                'dead_letter_24h' => $deadLetters,
+            ]);
+        }
+
+        $this->info("Notificações agendadas para processamento: {$count} (abandonadas recuperadas: {$abandonadas}; em dead letter nas últimas 24 h: {$deadLetters})");
 
         return self::SUCCESS;
     }
diff --git a/Modules/Core/app/Models/NotificationAttempt.php b/Modules/Core/app/Models/NotificationAttempt.php
index f3a1935..61d6c6b 100644
--- a/Modules/Core/app/Models/NotificationAttempt.php
+++ b/Modules/Core/app/Models/NotificationAttempt.php
@@ -42,4 +42,25 @@ public function notification(): BelongsTo
     {
         return $this->belongsTo(NotificationOutbox::class, 'notification_outbox_id');
     }
+
+    /**
+     * Assinala como abandonadas as tentativas que ficaram «a processar» quando o
+     * lease da notificação expirou. O resultado dessas tentativas é DESCONHECIDO
+     * (o fornecedor pode ou não ter recebido a mensagem): ficam registadas como
+     * tal em vez de parecerem estar ainda a decorrer.
+     */
+    public static function assinalarAbandonadas(int $empresaId, int $notificationOutboxId): int
+    {
+        return static::withoutGlobalScopes()
+            ->where('empresa_id', $empresaId)
+            ->where('notification_outbox_id', $notificationOutboxId)
+            ->where('status', 'processing')
+            ->update([
+                'status' => 'abandoned',
+                'error_code' => 'lease_expired',
+                'error_message' => 'O processamento foi interrompido antes de terminar; o resultado no fornecedor é desconhecido.',
+                'finished_at' => now(),
+                'updated_at' => now(),
+            ]);
+    }
 }
diff --git a/Modules/Core/app/Models/NotificationOutbox.php b/Modules/Core/app/Models/NotificationOutbox.php
index 7efe998..82a9c47 100644
--- a/Modules/Core/app/Models/NotificationOutbox.php
+++ b/Modules/Core/app/Models/NotificationOutbox.php
@@ -93,6 +93,20 @@ public function attemptsHistory(): HasMany
             ->orderBy('attempt_number');
     }
 
+    /**
+     * O fornecedor já recebeu (aceitou) esta mensagem — ainda que o estado possa
+     * evoluir depois (entregue, lida). Nunca pode voltar a ser enviada, mesmo que
+     * «accepted» não seja um estado terminal.
+     */
+    public function foiEntregueAoFornecedor(): bool
+    {
+        return in_array($this->status, [
+            self::STATUS_ACCEPTED,
+            self::STATUS_DELIVERED,
+            self::STATUS_READ,
+        ], true);
+    }
+
     public function isTerminal(): bool
     {
         return in_array($this->status, [
diff --git a/Modules/Core/app/Notifications/NotificationProcessor.php b/Modules/Core/app/Notifications/NotificationProcessor.php
index 52d3938..5e639e5 100644
--- a/Modules/Core/app/Notifications/NotificationProcessor.php
+++ b/Modules/Core/app/Notifications/NotificationProcessor.php
@@ -13,6 +13,14 @@
 
 class NotificationProcessor
 {
+    /**
+     * Duração do lease de uma notificação em processamento. Tem de ser MAIOR do
+     * que o timeout do ProcessNotificationJob (e, portanto, do pedido ao
+     * fornecedor): só depois de o worker ter sido interrompido é que outro pode
+     * recuperar a notificação.
+     */
+    public const LEASE_SEGUNDOS = 120;
+
     public function __construct(
         private readonly TenantManager $tenants,
         private readonly NotificationChannelRegistry $channels,
@@ -48,7 +56,10 @@ private function processWithinTenant(int $notificationId, int $empresaId): void
                 ->lockForUpdate()
                 ->first();
 
-            if (! $notification || $notification->isTerminal()) {
+            // «accepted» não é terminal (ainda pode passar a entregue ou lida), mas o
+            // fornecedor já tem a mensagem: um job repetido nunca a pode reenviar nem
+            // convertê-la em dead letter.
+            if (! $notification || $notification->isTerminal() || $notification->foiEntregueAoFornecedor()) {
                 return null;
             }
 
@@ -57,10 +68,16 @@ private function processWithinTenant(int $notificationId, int $empresaId): void
             }
 
             if ($notification->status === NotificationOutbox::STATUS_PROCESSING
-                && $notification->locked_at?->gt(now()->subMinutes(2))) {
+                && $notification->locked_at?->gt(now()->subSeconds(self::LEASE_SEGUNDOS))) {
                 return null;
             }
 
+            if ($notification->status === NotificationOutbox::STATUS_PROCESSING) {
+                // O lease ativo já devolveu null acima: este expirou e a tentativa
+                // anterior ficou sem resultado conhecido.
+                NotificationAttempt::assinalarAbandonadas($empresaId, (int) $notification->id);
+            }
+
             if ($notification->attempts >= $notification->max_attempts) {
                 $notification->forceFill([
                     'status' => NotificationOutbox::STATUS_DEAD_LETTER,
@@ -69,11 +86,7 @@ private function processWithinTenant(int $notificationId, int $empresaId): void
                     'lock_token' => null,
                 ])->save();
 
-                NotificationStateChanged::dispatch(
-                    (int) $notification->id,
-                    $empresaId,
-                    NotificationOutbox::STATUS_DEAD_LETTER,
-                );
+                $this->emitirEstado((int) $notification->id, $empresaId, NotificationOutbox::STATUS_DEAD_LETTER);
 
                 return null;
             }
@@ -155,11 +168,7 @@ private function processWithinTenant(int $notificationId, int $empresaId): void
                     'finished_at' => now(),
                 ])->save();
 
-                NotificationStateChanged::dispatch(
-                    (int) $notification->id,
-                    $empresaId,
-                    NotificationOutbox::STATUS_ACCEPTED,
-                );
+                $this->emitirEstado((int) $notification->id, $empresaId, NotificationOutbox::STATUS_ACCEPTED);
 
                 return;
             }
@@ -168,7 +177,7 @@ private function processWithinTenant(int $notificationId, int $empresaId): void
 
             $notification->refresh();
             if (in_array($notification->status, [NotificationOutbox::STATUS_FAILED, NotificationOutbox::STATUS_DEAD_LETTER], true)) {
-                NotificationStateChanged::dispatch((int) $notification->id, $empresaId, $notification->status);
+                $this->emitirEstado((int) $notification->id, $empresaId, $notification->status);
             }
         });
     }
@@ -196,7 +205,26 @@ private function recordFailure(array $claim, int $empresaId, string $message): v
 
             $notification->refresh();
             if (in_array($notification->status, [NotificationOutbox::STATUS_FAILED, NotificationOutbox::STATUS_DEAD_LETTER], true)) {
-                NotificationStateChanged::dispatch((int) $notification->id, $empresaId, $notification->status);
+                $this->emitirEstado((int) $notification->id, $empresaId, $notification->status);
+            }
+        });
+    }
+
+    /**
+     * Avisa os outros módulos (ex.: campanhas do Atelier) de que o estado mudou.
+     *
+     * Só depois de a transação ser confirmada e sem nunca a poder desfazer: se um
+     * listener falhar, o fornecedor já aceitou ou recusou a mensagem e reverter o
+     * estado levaria o recuperador a reenviá-la. Os outros módulos reconciliam a
+     * partir da própria Outbox.
+     */
+    private function emitirEstado(int $notificationId, int $empresaId, string $status): void
+    {
+        DB::afterCommit(function () use ($notificationId, $empresaId, $status): void {
+            try {
+                NotificationStateChanged::dispatch($notificationId, $empresaId, $status);
+            } catch (Throwable $exception) {
+                report($exception);
             }
         });
     }
diff --git a/Modules/Core/tests/Feature/NotificationProcessorTest.php b/Modules/Core/tests/Feature/NotificationProcessorTest.php
new file mode 100644
index 0000000..a606aa4
--- /dev/null
+++ b/Modules/Core/tests/Feature/NotificationProcessorTest.php
@@ -0,0 +1,432 @@
+<?php
+
+namespace Modules\Core\Tests\Feature;
+
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Artisan;
+use Illuminate\Support\Facades\Event;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Facades\Queue;
+use Modules\Core\Events\NotificationStateChanged;
+use Modules\Core\Jobs\ProcessNotificationJob;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Models\NotificationAttempt;
+use Modules\Core\Models\NotificationOutbox;
+use Modules\Core\Notifications\Contracts\NotificationChannel;
+use Modules\Core\Notifications\DTO\NotificationSendResult;
+use Modules\Core\Notifications\NotificationChannelRegistry;
+use Modules\Core\Notifications\NotificationDispatcher;
+use Modules\Core\Notifications\NotificationProcessor;
+use Modules\Core\Services\TenantManager;
+use RuntimeException;
+use Tests\TestCase;
+
+/**
+ * Garantias do processador da Outbox que se podem provar sem um fornecedor
+ * real: uma mensagem aceite nunca é reenviada por um job repetido; um worker
+ * antigo nunca sobrepõe o resultado de uma tentativa já recuperada; uma
+ * falha noutro módulo nunca desfaz o estado já confirmado; e uma empresa
+ * nunca processa notificações de outra.
+ *
+ * Não se promete «exatamente uma vez»: se o lease expira a meio de um envio,
+ * o fornecedor pode receber a mensagem duas vezes. O que é garantido é que
+ * o estado guardado fica coerente com a última tentativa.
+ */
+class NotificationProcessorTest extends TestCase
+{
+    use RefreshDatabase;
+
+    private Empresa $empresa;
+
+    private Empresa $outraEmpresa;
+
+    private CanalDeTeste $canal;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        Queue::fake();
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Empresa A', 'nif' => '100000401']);
+        $this->outraEmpresa = Empresa::create(['nome_comercial' => 'Empresa B', 'nif' => '100000402']);
+
+        $this->canal = new CanalDeTeste;
+        app(NotificationChannelRegistry::class)->register($this->canal);
+    }
+
+    // ---------------------------------------------------------------------
+    // Uma mensagem já entregue ao fornecedor nunca volta a ser enviada
+    // ---------------------------------------------------------------------
+
+    public function test_um_segundo_job_nao_reenvia_uma_notificacao_ja_aceite(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-aceite');
+
+        // O job original e o job reagendado pelo recuperador chegam ao worker um a seguir ao outro.
+        $this->processar($notificacao);
+        $this->processar($notificacao);
+
+        $this->assertSame(1, $this->canal->envios, 'O fornecedor só pode receber a mensagem uma vez.');
+        $notificacao->refresh();
+        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
+        $this->assertSame(1, $notificacao->attempts);
+        $this->assertSame(1, $this->tentativas($notificacao)->count());
+    }
+
+    public function test_um_job_repetido_nao_converte_uma_notificacao_aceite_em_dead_letter(): void
+    {
+        // Aceite precisamente na última tentativa permitida.
+        $notificacao = $this->novaNotificacao('chave-ultima-tentativa', maxAttempts: 2);
+        $notificacao->forceFill([
+            'status' => NotificationOutbox::STATUS_ACCEPTED,
+            'attempts' => 2,
+            'accepted_at' => now(),
+            'provider_message_id' => 'msg-original',
+        ])->save();
+
+        Event::fake([NotificationStateChanged::class]);
+
+        $this->processar($notificacao);
+
+        $notificacao->refresh();
+        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
+        $this->assertSame('msg-original', $notificacao->provider_message_id);
+        $this->assertSame(0, $this->canal->envios);
+        Event::assertNotDispatched(NotificationStateChanged::class);
+    }
+
+    public function test_estados_entregue_e_lido_tambem_nao_sao_reenviados(): void
+    {
+        foreach ([NotificationOutbox::STATUS_DELIVERED, NotificationOutbox::STATUS_READ] as $indice => $estado) {
+            $notificacao = $this->novaNotificacao("chave-{$estado}");
+            $notificacao->forceFill(['status' => $estado, 'attempts' => 1])->save();
+
+            $this->processar($notificacao);
+
+            $this->assertSame($estado, $notificacao->fresh()->status);
+        }
+
+        $this->assertSame(0, $this->canal->envios);
+    }
+
+    // ---------------------------------------------------------------------
+    // Um problema noutro módulo nunca desfaz o estado já confirmado
+    // ---------------------------------------------------------------------
+
+    public function test_falha_de_um_listener_nao_desfaz_o_estado_aceite_nem_provoca_reenvio(): void
+    {
+        Event::listen(NotificationStateChanged::class, function (): void {
+            throw new RuntimeException('listener de outro módulo avariado');
+        });
+
+        $notificacao = $this->novaNotificacao('chave-listener');
+        $jobsAntes = Queue::pushed(ProcessNotificationJob::class)->count();
+
+        $this->processar($notificacao);
+
+        // O fornecedor aceitou a mensagem: o estado fica registado, mesmo com o listener avariado.
+        $notificacao->refresh();
+        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
+        $this->assertNull($notificacao->lock_token);
+        $this->assertSame('accepted', $this->tentativas($notificacao)->first()->status);
+
+        // E nem o recuperador nem um job repetido voltam a enviá-la.
+        Artisan::call('notifications:recover');
+        $this->assertCount($jobsAntes, Queue::pushed(ProcessNotificationJob::class), 'O recuperador não pode reagendar uma mensagem aceite.');
+
+        $this->processar($notificacao);
+        $this->assertSame(1, $this->canal->envios);
+    }
+
+    // ---------------------------------------------------------------------
+    // Um worker antigo nunca sobrepõe a tentativa já recuperada
+    // ---------------------------------------------------------------------
+
+    public function test_worker_antigo_nao_sobrepoe_o_resultado_da_tentativa_recuperada(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-worker-antigo');
+
+        // Durante o envio do worker 1 o lease expira e um segundo worker recupera e conclui a mensagem.
+        $this->canal->aoEnviar = function (NotificationOutbox $enviada, int $numeroDoEnvio) use ($notificacao): NotificationSendResult {
+            if ($numeroDoEnvio === 1) {
+                $this->expirarLease($notificacao);
+                $this->processar($notificacao);
+            }
+
+            return NotificationSendResult::accepted('teste', "msg-{$numeroDoEnvio}");
+        };
+
+        $this->processar($notificacao);
+
+        $notificacao->refresh();
+        $this->assertSame(2, $this->canal->envios, 'Com o lease expirado a meio do envio o fornecedor pode receber duas vezes…');
+        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
+        $this->assertSame('msg-2', $notificacao->provider_message_id, '…mas prevalece o resultado da tentativa recuperada.');
+        $this->assertSame(2, $notificacao->attempts);
+
+        $tentativas = $this->tentativas($notificacao);
+        $this->assertSame('accepted', $tentativas->firstWhere('attempt_number', 2)->status);
+        $this->assertNotSame('accepted', $tentativas->firstWhere('attempt_number', 1)->status, 'O worker antigo não pode marcar a sua tentativa como aceite.');
+    }
+
+    public function test_falha_tardia_de_um_worker_antigo_nao_estraga_a_tentativa_recuperada(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-falha-tardia');
+        $jobsAntes = Queue::pushed(ProcessNotificationJob::class)->count();
+
+        $this->canal->aoEnviar = function (NotificationOutbox $enviada, int $numeroDoEnvio) use ($notificacao): NotificationSendResult {
+            if ($numeroDoEnvio === 1) {
+                $this->expirarLease($notificacao);
+                $this->processar($notificacao);
+
+                throw new RuntimeException('o worker antigo só falha depois de a tentativa ter sido recuperada');
+            }
+
+            return NotificationSendResult::accepted('teste', 'msg-recuperada');
+        };
+
+        $this->processar($notificacao);
+
+        $notificacao->refresh();
+        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
+        $this->assertSame('msg-recuperada', $notificacao->provider_message_id);
+        $this->assertNull($notificacao->last_error, 'A falha tardia do worker antigo não pode ser registada.');
+        $this->assertCount($jobsAntes, Queue::pushed(ProcessNotificationJob::class), 'A falha tardia não pode agendar um retry.');
+    }
+
+    // ---------------------------------------------------------------------
+    // Leases e recuperação
+    // ---------------------------------------------------------------------
+
+    public function test_lease_ativo_impede_um_segundo_worker_e_lease_expirado_permite_recuperar(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-lease');
+        $notificacao->forceFill([
+            'status' => NotificationOutbox::STATUS_PROCESSING,
+            'attempts' => 1,
+            'locked_at' => now()->subSeconds(30),
+            'lock_token' => 'worker-vivo',
+        ])->save();
+
+        $this->processar($notificacao);
+        $this->assertSame(0, $this->canal->envios, 'Com o lease ativo ninguém mais pode enviar.');
+
+        $this->expirarLease($notificacao);
+        $this->processar($notificacao);
+
+        $this->assertSame(1, $this->canal->envios);
+        $notificacao->refresh();
+        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
+        $this->assertSame(2, $notificacao->attempts, 'A tentativa perdida conta para o limite.');
+    }
+
+    public function test_tentativa_perdida_fica_registada_como_abandonada_e_nao_como_a_processar(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-abandonada');
+
+        // Um worker reivindicou a notificação e morreu sem concluir.
+        $notificacao->forceFill([
+            'status' => NotificationOutbox::STATUS_PROCESSING,
+            'attempts' => 1,
+            'locked_at' => now()->subMinutes(10),
+            'lock_token' => 'worker-morto',
+        ])->save();
+        NotificationAttempt::create([
+            'empresa_id' => $this->empresa->id,
+            'notification_outbox_id' => $notificacao->id,
+            'attempt_number' => 1,
+            'status' => 'processing',
+            'started_at' => now()->subMinutes(10),
+        ]);
+
+        $this->processar($notificacao);
+
+        $primeira = $this->tentativas($notificacao)->firstWhere('attempt_number', 1);
+        $this->assertSame('abandoned', $primeira->status, 'O resultado da tentativa perdida é desconhecido e tem de ficar assinalado.');
+        $this->assertSame('lease_expired', $primeira->error_code);
+        $this->assertNotNull($primeira->finished_at);
+        $this->assertSame('accepted', $this->tentativas($notificacao)->firstWhere('attempt_number', 2)->status);
+    }
+
+    public function test_recuperador_reagenda_pendentes_e_abandonadas_mas_nunca_as_aceites(): void
+    {
+        $devida = $this->novaNotificacao('rec-pendente-devida');
+        $retry = $this->novaNotificacao('rec-retry-devido');
+        $retry->forceFill(['status' => NotificationOutbox::STATUS_RETRY_SCHEDULED, 'attempts' => 1, 'available_at' => now()->subMinute()])->save();
+        $futura = $this->novaNotificacao('rec-retry-futuro');
+        $futura->forceFill(['status' => NotificationOutbox::STATUS_RETRY_SCHEDULED, 'attempts' => 1, 'available_at' => now()->addHour()])->save();
+        $abandonada = $this->novaNotificacao('rec-processing-abandonado');
+        $abandonada->forceFill(['status' => NotificationOutbox::STATUS_PROCESSING, 'attempts' => 1, 'locked_at' => now()->subMinutes(5), 'lock_token' => 'morto'])->save();
+        $viva = $this->novaNotificacao('rec-processing-vivo');
+        $viva->forceFill(['status' => NotificationOutbox::STATUS_PROCESSING, 'attempts' => 1, 'locked_at' => now()->subSeconds(20), 'lock_token' => 'vivo'])->save();
+        $aceite = $this->novaNotificacao('rec-aceite');
+        $aceite->forceFill(['status' => NotificationOutbox::STATUS_ACCEPTED, 'attempts' => 1])->save();
+        $entregue = $this->novaNotificacao('rec-entregue');
+        $entregue->forceFill(['status' => NotificationOutbox::STATUS_DELIVERED, 'attempts' => 1])->save();
+
+        Queue::fake(); // esquece os jobs iniciais: só interessam os que o recuperador agendar
+
+        Artisan::call('notifications:recover');
+
+        $reagendadas = Queue::pushed(ProcessNotificationJob::class)->map(fn ($job) => $job->notificationId)->sort()->values()->all();
+        $this->assertSame(
+            collect([$devida, $retry, $abandonada])->pluck('id')->sort()->values()->all(),
+            $reagendadas,
+            'Só pendentes devidas, retries devidos e leases abandonados são reagendados.'
+        );
+
+        $abandonada->refresh();
+        $this->assertSame(NotificationOutbox::STATUS_RETRY_SCHEDULED, $abandonada->status);
+        $this->assertNull($abandonada->lock_token);
+        $this->assertSame('vivo', $viva->fresh()->lock_token, 'Um lease ativo não pode ser tocado.');
+    }
+
+    public function test_abandonada_no_limite_de_tentativas_vai_para_dead_letter_sem_novo_envio(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-limite', maxAttempts: 2);
+        $notificacao->forceFill([
+            'status' => NotificationOutbox::STATUS_PROCESSING,
+            'attempts' => 2,
+            'locked_at' => now()->subMinutes(5),
+            'lock_token' => 'morto',
+        ])->save();
+
+        Artisan::call('notifications:recover');
+        $this->processar($notificacao);
+
+        $this->assertSame(NotificationOutbox::STATUS_DEAD_LETTER, $notificacao->fresh()->status);
+        $this->assertSame(0, $this->canal->envios, 'Passado o limite não há mais nenhuma tentativa.');
+    }
+
+    public function test_recuperador_assinala_abandonadas_e_dead_letter_recentes_para_monitorizacao(): void
+    {
+        $abandonada = $this->novaNotificacao('mon-abandonada');
+        $abandonada->forceFill(['status' => NotificationOutbox::STATUS_PROCESSING, 'attempts' => 1, 'locked_at' => now()->subMinutes(5), 'lock_token' => 'morto'])->save();
+        $morta = $this->novaNotificacao('mon-dead-letter');
+        $morta->forceFill(['status' => NotificationOutbox::STATUS_DEAD_LETTER, 'attempts' => 5, 'failed_at' => now()->subHour()])->save();
+        $antiga = $this->novaNotificacao('mon-dead-letter-antiga');
+        $antiga->forceFill(['status' => NotificationOutbox::STATUS_DEAD_LETTER, 'attempts' => 5, 'failed_at' => now()->subDays(3)])->save();
+
+        Log::spy();
+
+        Artisan::call('notifications:recover');
+
+        $saida = Artisan::output(); // só se pode ler uma vez
+
+        $this->assertStringContainsString('abandonadas recuperadas: 1', $saida);
+        $this->assertMatchesRegularExpression('/dead letter nas .+: 1\)/u', $saida);
+        Log::shouldHaveReceived('warning')->withArgs(fn (string $mensagem, array $contexto = []) => ($contexto['abandonadas'] ?? null) === 1)->once();
+        Log::shouldHaveReceived('warning')->withArgs(fn (string $mensagem, array $contexto = []) => ($contexto['dead_letter_24h'] ?? null) === 1)->once();
+    }
+
+    public function test_o_timeout_do_fornecedor_cabe_dentro_do_lease(): void
+    {
+        $job = new ProcessNotificationJob(1, 1);
+
+        $this->assertLessThan($job->timeout, (int) config('atelier.http_timeout_segundos', 15), 'O pedido ao fornecedor tem de acabar antes de o worker ser interrompido.');
+        $this->assertLessThan(NotificationProcessor::LEASE_SEGUNDOS, $job->timeout, 'O worker tem de ser interrompido antes de o lease poder ser recuperado por outro.');
+    }
+
+    // ---------------------------------------------------------------------
+    // Isolamento entre empresas
+    // ---------------------------------------------------------------------
+
+    public function test_processador_ignora_notificacoes_de_outra_empresa(): void
+    {
+        $notificacao = $this->novaNotificacao('chave-isolamento');
+
+        app(NotificationProcessor::class)->process($notificacao->id, $this->outraEmpresa->id);
+
+        $this->assertSame(0, $this->canal->envios);
+        $notificacao->refresh();
+        $this->assertSame(NotificationOutbox::STATUS_PENDING, $notificacao->status);
+        $this->assertSame(0, $notificacao->attempts);
+    }
+
+    public function test_dispatcher_recusa_empresa_diferente_do_tenant_e_isola_chaves_por_empresa(): void
+    {
+        $tenants = app(TenantManager::class);
+        $dispatcher = app(NotificationDispatcher::class);
+
+        $tenants->set($this->empresa->id);
+        try {
+            $dispatcher->dispatch('teste.canal', '+244923000001', ['corpo' => 'x'], 'chave-tenant', empresaId: $this->outraEmpresa->id);
+            $this->fail('Devia recusar uma empresa diferente da do tenant atual.');
+        } catch (RuntimeException $excecao) {
+            $this->assertStringContainsString('tenant', $excecao->getMessage());
+        }
+        $tenants->clear();
+
+        // A mesma chave, em duas empresas, são duas notificações diferentes.
+        $a = $dispatcher->dispatch('teste.canal', '+244923000001', ['corpo' => 'x'], 'chave-partilhada', empresaId: $this->empresa->id);
+        $b = $dispatcher->dispatch('teste.canal', '+244923000002', ['corpo' => 'y'], 'chave-partilhada', empresaId: $this->outraEmpresa->id);
+
+        $this->assertNotSame($a->id, $b->id);
+        $this->assertSame($this->empresa->id, (int) $a->empresa_id);
+        $this->assertSame($this->outraEmpresa->id, (int) $b->empresa_id);
+    }
+
+    // ---------------------------------------------------------------------
+    // Preparação
+    // ---------------------------------------------------------------------
+
+    private function novaNotificacao(string $chave, int $maxAttempts = 5): NotificationOutbox
+    {
+        return app(NotificationDispatcher::class)->dispatch(
+            channel: 'teste.canal',
+            recipient: '+244923000000',
+            payload: ['corpo' => "Mensagem {$chave}"],
+            idempotencyKey: $chave,
+            maxAttempts: $maxAttempts,
+            empresaId: $this->empresa->id,
+        );
+    }
+
+    private function processar(NotificationOutbox $notificacao): void
+    {
+        app(NotificationProcessor::class)->process((int) $notificacao->id, (int) $notificacao->empresa_id);
+    }
+
+    private function expirarLease(NotificationOutbox $notificacao): void
+    {
+        NotificationOutbox::withoutGlobalScopes()
+            ->whereKey($notificacao->id)
+            ->update(['locked_at' => now()->subMinutes(10)]);
+    }
+
+    /** @return Collection<int, NotificationAttempt> */
+    private function tentativas(NotificationOutbox $notificacao)
+    {
+        return NotificationAttempt::withoutGlobalScopes()
+            ->where('notification_outbox_id', $notificacao->id)
+            ->orderBy('attempt_number')
+            ->get();
+    }
+}
+
+/** Canal sem fornecedor: aceita tudo, conta os envios e deixa o teste interferir a meio de cada um. */
+final class CanalDeTeste implements NotificationChannel
+{
+    public int $envios = 0;
+
+    /** @var (callable(NotificationOutbox, int): NotificationSendResult)|null */
+    public $aoEnviar = null;
+
+    public function identifier(): string
+    {
+        return 'teste.canal';
+    }
+
+    public function send(NotificationOutbox $notification): NotificationSendResult
+    {
+        $this->envios++;
+
+        if ($this->aoEnviar !== null) {
+            return ($this->aoEnviar)($notification, $this->envios);
+        }
+
+        return NotificationSendResult::accepted('teste', "msg-{$this->envios}");
+    }
+}
diff --git a/Modules/EstudioMusica/app/Http/Controllers/ProjetoMusicalController.php b/Modules/EstudioMusica/app/Http/Controllers/ProjetoMusicalController.php
index e3c051c..dcdaaba 100644
--- a/Modules/EstudioMusica/app/Http/Controllers/ProjetoMusicalController.php
+++ b/Modules/EstudioMusica/app/Http/Controllers/ProjetoMusicalController.php
@@ -2,12 +2,12 @@
 
 namespace Modules\EstudioMusica\Http\Controllers;
 
+use DomainException;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Routing\Controller;
 use Illuminate\Validation\Rule;
 use Illuminate\View\View;
-use DomainException;
 use InvalidArgumentException;
 use Modules\Core\Models\User;
 use Modules\EstudioMusica\Http\Requests\AtualizarPortfolioRequest;
@@ -133,7 +133,7 @@ public function registrarPagamento(Request $request, int $projeto): RedirectResp
                 $projeto,
                 $fatura,
                 $dados['meio_pagamento'],
-                isset($dados['valor']) ? (float) $dados['valor'] : null,
+                $dados['valor'] ?? null,
             );
         } catch (InvalidArgumentException|DomainException $e) {
             return back()->with('erro', $e->getMessage());
diff --git a/Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php b/Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php
index 75d50d8..a9c33da 100644
--- a/Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php
+++ b/Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php
@@ -79,7 +79,7 @@ public function gerarFaturaSinal(ProjetoMusical $projeto): Fatura
                 'descricao' => "Sinal ({$percentual}%) - Projeto \"{$projeto->nome}\"",
                 'quantidade' => 1,
                 'preco_unitario' => Dinheiro::formatar($sinal),
-                'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
+                'taxa_iva' => (string) config('faturacao.taxa_iva_geral'),
             ]]);
 
             $projeto->faturas()->attach($fatura->id, ['tipo' => 'sinal']);
@@ -118,7 +118,7 @@ public function gerarFaturaSaldoFinal(ProjetoMusical $projeto): Fatura
                 'descricao' => "Saldo final - Projeto \"{$projeto->nome}\"",
                 'quantidade' => 1,
                 'preco_unitario' => Dinheiro::formatar($restante),
-                'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
+                'taxa_iva' => (string) config('faturacao.taxa_iva_geral'),
             ]]);
 
             $projeto->faturas()->attach($fatura->id, ['tipo' => 'saldo_final']);
@@ -188,12 +188,12 @@ public function cobrarSessao(SessaoEstudio $sessao): ?Fatura
             ->first();
 
         if ($cupom) {
-            $percentual = (float) $cupom->percentual_desconto;
+            $percentual = (string) $cupom->percentual_desconto;
             $liquido = $total - $this->percentagemEmCentimos($total, $percentual);
 
             if ($liquido > 0) {
                 $total = $liquido;
-                $descricao .= ' — desconto '.rtrim(rtrim(number_format($percentual, 2, '.', ''), '0'), '.')."% (cupão {$cupom->codigo})";
+                $descricao .= ' — desconto '.rtrim(rtrim(Dinheiro::formatar(Dinheiro::pontosBase($percentual)), '0'), '.')."% (cupão {$cupom->codigo})";
             } else {
                 $cupom = null; // desconto total: não gasta o cupão numa fatura que não se emite
             }
@@ -204,7 +204,7 @@ public function cobrarSessao(SessaoEstudio $sessao): ?Fatura
             'quantidade' => 1,
             'preco_unitario' => Dinheiro::formatar($total),
             'preco_unitario_base' => Dinheiro::formatar($precoUnitarioBase),
-            'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
+            'taxa_iva' => (string) config('faturacao.taxa_iva_geral'),
         ]]);
 
         $cupom?->marcarComoUsado();
@@ -225,7 +225,7 @@ public function cobrarSessao(SessaoEstudio $sessao): ?Fatura
      *
      * @throws InvalidArgumentException|DomainException
      */
-    public function registrarRecibo(ProjetoMusical $projeto, Fatura $fatura, string $meioPagamento, ?float $valor = null): Recibo
+    public function registrarRecibo(ProjetoMusical $projeto, Fatura $fatura, string $meioPagamento, string|int|float|null $valor = null): Recibo
     {
         return DB::transaction(function () use ($projeto, $fatura, $meioPagamento, $valor) {
             $pertenceAoProjeto = (int) $fatura->empresa_id === (int) $projeto->empresa_id
@@ -262,7 +262,7 @@ public function registrarRecibo(ProjetoMusical $projeto, Fatura $fatura, string
     }
 
     /**
-     * @param  array<int, array{descricao: string, quantidade: int|float, preco_unitario: string|float, taxa_iva: float}>  $linhas
+     * @param  array<int, array{descricao: string, quantidade: int|float, preco_unitario: string, taxa_iva: string}>  $linhas
      */
     protected function criarEEmitirFatura(Empresa $empresa, Cliente $cliente, array $linhas): Fatura
     {
@@ -272,9 +272,9 @@ protected function criarEEmitirFatura(Empresa $empresa, Cliente $cliente, array
     }
 
     /** $percentual% de um valor em cêntimos, arredondado a meio para cima (40,00% de 100000,00 → 40000,00). */
-    protected function percentagemEmCentimos(int $centimos, float $percentual): int
+    protected function percentagemEmCentimos(int $centimos, string|int|float $percentual): int
     {
-        $pontosBase = (int) round($percentual * 100);
+        $pontosBase = Dinheiro::pontosBase($percentual);
 
         return intdiv($centimos * $pontosBase + 5000, 10000);
     }
diff --git a/Modules/EstudioMusica/tests/Feature/IsolamentoEstudioTest.php b/Modules/EstudioMusica/tests/Feature/IsolamentoEstudioTest.php
new file mode 100644
index 0000000..c034319
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Feature/IsolamentoEstudioTest.php
@@ -0,0 +1,192 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Http\UploadedFile;
+use Illuminate\Support\Facades\DB;
+use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
+use Modules\EstudioMusica\Tests\Support\CriaEstudioComDados;
+use PHPUnit\Framework\Attributes\DataProvider;
+use Tests\TestCase;
+
+require_once __DIR__.'/../Support/ComEstudioAutenticado.php';
+require_once __DIR__.'/../Support/CriaEstudioComDados.php';
+
+/**
+ * Isolamento entre estúdios (empresas) na área da equipa. O Administrador do
+ * estúdio B é o «intruso»: tenta ler e alterar, por ID, tudo o que pertence
+ * ao estúdio A. Em todos os casos a resposta tem de ser 404 (nunca 200,
+ * 302 de sucesso ou 403, que confirmariam que o registo existe) e a base de
+ * dados tem de ficar exatamente como estava.
+ */
+class IsolamentoEstudioTest extends TestCase
+{
+    use ComEstudioAutenticado;
+    use CriaEstudioComDados;
+    use RefreshDatabase;
+
+    /** Tabelas de negócio onde um intruso nunca pode deixar rasto. */
+    private const TABELAS = [
+        'clientes',
+        'salas_estudio',
+        'projetos_musicais',
+        'faixas_musicais',
+        'versoes_audio',
+        'sessoes_estudio',
+        'solicitacoes_orcamento',
+        'reclamacoes_estudio',
+        'faturas',
+        'recibos',
+        'notification_outbox',
+    ];
+
+    /** @var array<string, mixed> Recursos do estúdio A (o alvo). */
+    protected array $a;
+
+    /** @var array<string, mixed> Recursos do estúdio B (o intruso). */
+    protected array $b;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->a = $this->criarEstudioComDados('510000401', 'SEGREDO-A');
+        $this->b = $this->criarEstudioComDados('510000402', 'SEGREDO-B');
+    }
+
+    /**
+     * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: array<string, mixed>}>
+     */
+    public static function rotasComId(): array
+    {
+        $estado = 'mixagem';
+
+        return [
+            'projeto: ver' => ['GET', 'estudiomusica.projetos.show', ['projeto' => 'projeto'], []],
+            'projeto: estado' => ['PUT', 'estudiomusica.projetos.estado', ['projeto' => 'projeto'], ['estado' => $estado]],
+            'projeto: portfólio' => ['PUT', 'estudiomusica.projetos.portfolio', ['projeto' => 'projeto'], ['destaque_portfolio' => 0]],
+            'projeto: link do portal' => ['POST', 'estudiomusica.projetos.link-portal', ['projeto' => 'projeto'], []],
+            'projeto: sinal' => ['POST', 'estudiomusica.projetos.sinal', ['projeto' => 'projeto'], []],
+            'projeto: saldo final' => ['POST', 'estudiomusica.projetos.saldo-final', ['projeto' => 'projeto'], []],
+            'projeto: recibo' => ['POST', 'estudiomusica.projetos.recibos', ['projeto' => 'projeto'], ['fatura_id' => 1, 'meio_pagamento' => 'dinheiro']],
+            'projeto: nova faixa' => ['POST', 'estudiomusica.projetos.faixas.store', ['projeto' => 'projeto'], ['nome' => 'Faixa do intruso']],
+            'faixa: estado' => ['PUT', 'estudiomusica.faixas.estado', ['faixa' => 'faixa'], ['estado' => $estado]],
+            'faixa: nova versão' => ['POST', 'estudiomusica.faixas.versoes.store', ['faixa' => 'faixa'], ['rotulo' => 'Versão do intruso', 'ficheiro' => 'AUDIO']],
+            'versão: reproduzir' => ['GET', 'estudiomusica.versoes.reproduzir', ['versao' => 'versao'], []],
+            'versão: lembrete de aprovação' => ['POST', 'estudiomusica.versoes.lembrete-aprovacao', ['versao' => 'versao'], []],
+            'sala: atualizar' => ['PUT', 'estudiomusica.salas.update', ['sala' => 'sala'], ['nome' => 'Sala do intruso', 'preco_hora' => 1]],
+            'cliente: perfil de artista' => ['PUT', 'estudiomusica.clientes.perfil-artista', ['cliente' => 'cliente'], ['nome_artistico' => 'Intruso']],
+            'sessão: reagendar' => ['PUT', 'estudiomusica.sessoes.reagendar', ['sessao' => 'sessao'], ['inicio_previsto' => '2031-01-01 10:00:00', 'fim_previsto' => '2031-01-01 11:00:00']],
+            'sessão: cancelar' => ['DELETE', 'estudiomusica.sessoes.cancelar', ['sessao' => 'sessao'], []],
+            'sessão: check-in' => ['POST', 'estudiomusica.sessoes.check-in', ['sessao' => 'sessao'], []],
+            'sessão: check-out' => ['POST', 'estudiomusica.sessoes.check-out', ['sessao' => 'sessao'], []],
+            'sessão: faturar' => ['POST', 'estudiomusica.sessoes.faturar', ['sessao' => 'sessao'], []],
+            'solicitação: estado' => ['PUT', 'estudiomusica.solicitacoes.estado', ['solicitacao' => 'solicitacao'], ['estado' => 'descartado']],
+            'solicitação: converter' => ['POST', 'estudiomusica.solicitacoes.converter', ['solicitacao' => 'solicitacao'], []],
+            'reclamação: atualizar' => ['PUT', 'estudiomusica.reclamacoes.atualizar', ['reclamacao' => 'reclamacao'], ['estado' => 'resolvida', 'resposta' => 'Resposta do intruso']],
+        ];
+    }
+
+    /**
+     * @param  array<string, string>  $parametros  nome do parâmetro de rota => chave do recurso do estúdio A
+     * @param  array<string, mixed>  $dados
+     */
+    #[DataProvider('rotasComId')]
+    public function test_staff_de_outro_estudio_recebe_404_e_nada_muda(string $metodo, string $rota, array $parametros, array $dados): void
+    {
+        $antes = $this->fotografia();
+
+        $ficheiros = [];
+        if (($dados['ficheiro'] ?? null) === 'AUDIO') {
+            unset($dados['ficheiro']);
+            $ficheiros['ficheiro'] = UploadedFile::fake()->create('intruso.mp3', 100, 'audio/mpeg');
+        }
+
+        $url = route($rota, array_map(fn (string $chave) => $this->a[$chave]->id, $parametros));
+
+        $this->actingAs($this->b['admin'])
+            ->call($metodo, $url, $dados, [], $ficheiros)
+            ->assertNotFound();
+
+        $this->assertSame($antes, $this->fotografia(), 'Um pedido do estúdio B não pode alterar nenhuma tabela de negócio.');
+    }
+
+    public function test_listagens_so_mostram_dados_do_proprio_estudio(): void
+    {
+        $listagens = [
+            'estudiomusica.projetos.index',
+            'estudiomusica.salas.index',
+            'estudiomusica.solicitacoes.index',
+            'estudiomusica.reclamacoes.index',
+            'estudiomusica.sessoes.index',
+        ];
+
+        foreach ($listagens as $rota) {
+            $this->actingAs($this->b['admin'])
+                ->get(route($rota))
+                ->assertOk()
+                ->assertDontSee('SEGREDO-A');
+        }
+
+        // Controlo positivo: as páginas mostram mesmo dados quando são do próprio estúdio.
+        $this->actingAs($this->b['admin'])->get(route('estudiomusica.projetos.index'))->assertSee('SEGREDO-B');
+        $this->actingAs($this->b['admin'])->get(route('estudiomusica.salas.index'))->assertSee('SEGREDO-B');
+        $this->actingAs($this->b['admin'])->get(route('estudiomusica.solicitacoes.index'))->assertSee('SEGREDO-B');
+        $this->actingAs($this->b['admin'])->get(route('estudiomusica.reclamacoes.index'))->assertSee('SEGREDO-B');
+    }
+
+    public function test_administrador_de_outro_estudio_nao_atribui_nem_remove_papeis_a_utilizadores_do_estudio_a(): void
+    {
+        $papeisAntes = DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_id')->get()->map(fn ($r) => (array) $r)->all();
+
+        foreach (['estudiomusica.equipa.atribuir', 'estudiomusica.equipa.remover'] as $rota) {
+            $this->actingAs($this->b['admin'])
+                ->post(route($rota), ['utilizador_id' => $this->a['admin']->id, 'papel' => 'Recepcao'])
+                ->assertSessionHasErrors('utilizador_id');
+        }
+
+        $papeisDepois = DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_id')->get()->map(fn ($r) => (array) $r)->all();
+        $this->assertSame($papeisAntes, $papeisDepois, 'Nenhum papel pode ter sido atribuído ou removido.');
+    }
+
+    public function test_nao_se_cria_projeto_nem_sessao_com_referencias_do_outro_estudio(): void
+    {
+        $antes = $this->fotografia();
+
+        // O cliente é do estúdio A: a validação tem de o recusar.
+        $this->actingAs($this->b['admin'])
+            ->post(route('estudiomusica.projetos.store'), [
+                'cliente_id' => $this->a['cliente']->id,
+                'nome' => 'Projeto com cliente alheio',
+                'tipo_cobranca' => 'hora',
+            ])
+            ->assertSessionHasErrors('cliente_id');
+
+        // Sala, cliente e projeto são do estúdio A.
+        $this->actingAs($this->b['admin'])
+            ->post(route('estudiomusica.sessoes.store'), [
+                'sala_estudio_id' => $this->a['sala']->id,
+                'cliente_id' => $this->a['cliente']->id,
+                'projeto_musical_id' => $this->a['projeto']->id,
+                'tipo_servico' => 'mixagem',
+                'inicio_previsto' => now()->addDays(3)->format('Y-m-d H:00:00'),
+                'fim_previsto' => now()->addDays(3)->addHour()->format('Y-m-d H:00:00'),
+            ])
+            ->assertSessionHasErrors(['sala_estudio_id', 'cliente_id', 'projeto_musical_id']);
+
+        $this->assertSame($antes, $this->fotografia());
+    }
+
+    /** O estado completo das tabelas de negócio (de todas as empresas) num instante. */
+    protected function fotografia(): array
+    {
+        $estado = [];
+
+        foreach (self::TABELAS as $tabela) {
+            $estado[$tabela] = DB::table($tabela)->orderBy('id')->get()->map(fn ($linha) => (array) $linha)->all();
+        }
+
+        return $estado;
+    }
+}
diff --git a/Modules/EstudioMusica/tests/Feature/IsolamentoPortalEstudioTest.php b/Modules/EstudioMusica/tests/Feature/IsolamentoPortalEstudioTest.php
new file mode 100644
index 0000000..ac4a7cd
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Feature/IsolamentoPortalEstudioTest.php
@@ -0,0 +1,172 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\DB;
+use Modules\EstudioMusica\Models\FaixaMusical;
+use Modules\EstudioMusica\Models\ProjetoMusical;
+use Modules\EstudioMusica\Models\VersaoAudio;
+use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
+use Modules\EstudioMusica\Tests\Support\CriaEstudioComDados;
+use Modules\Faturacao\Models\Cliente;
+use Tests\TestCase;
+
+require_once __DIR__.'/../Support/ComEstudioAutenticado.php';
+require_once __DIR__.'/../Support/CriaEstudioComDados.php';
+
+/**
+ * Isolamento no Portal do Cliente, que se autentica só por token (sem login).
+ * O token do artista A1 do estúdio A:
+ *  - nunca alcança versões de outro artista do MESMO estúdio (403);
+ *  - nunca alcança versões de OUTRO estúdio (404, o registo nem «existe»);
+ *  - só funciona enquanto não expira.
+ */
+class IsolamentoPortalEstudioTest extends TestCase
+{
+    use ComEstudioAutenticado;
+    use CriaEstudioComDados;
+    use RefreshDatabase;
+
+    private const TOKEN = 'token-do-artista-a1';
+
+    /** @var array<string, mixed> Estúdio A, artista A1 (o dono do token). */
+    protected array $a1;
+
+    /** @var array<string, mixed> Outro artista (A2) do mesmo estúdio A. */
+    protected array $a2;
+
+    /** @var array<string, mixed> Estúdio B, de outra empresa. */
+    protected array $b;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->a1 = $this->criarEstudioComDados('510000501', 'SEGREDO-A1');
+        $this->b = $this->criarEstudioComDados('510000502', 'SEGREDO-B');
+        $this->a2 = $this->outroArtistaDoMesmoEstudio($this->a1, 'SEGREDO-A2');
+
+        $this->darTokenAoArtista($this->a1['cliente'], self::TOKEN);
+    }
+
+    public function test_o_portal_so_mostra_os_projetos_do_proprio_artista(): void
+    {
+        $this->get(route('estudiomusica.portal.mostrar', ['token' => self::TOKEN]))
+            ->assertOk()
+            ->assertSee('SEGREDO-A1')
+            ->assertDontSee('SEGREDO-A2')
+            ->assertDontSee('SEGREDO-B');
+    }
+
+    public function test_token_de_um_artista_nao_alcanca_versoes_de_outro_artista_do_mesmo_estudio(): void
+    {
+        $antes = $this->fotografia();
+
+        foreach ($this->acoesSobreUmaVersao($this->a2['versao']) as [$metodo, $url, $dados]) {
+            $resposta = $this->call($metodo, $url, $dados);
+
+            $this->assertContains(
+                $resposta->getStatusCode(),
+                [403, 404],
+                "{$metodo} {$url} devia ser recusado (recebeu {$resposta->getStatusCode()})."
+            );
+        }
+
+        $this->assertSame($antes, $this->fotografia(), 'Nenhuma versão ou marcador do outro artista pode ter sido tocado.');
+    }
+
+    public function test_token_nao_alcanca_versoes_de_outro_estudio(): void
+    {
+        $antes = $this->fotografia();
+
+        foreach ($this->acoesSobreUmaVersao($this->b['versao']) as [$metodo, $url, $dados]) {
+            $this->call($metodo, $url, $dados)->assertNotFound();
+        }
+
+        $this->assertSame($antes, $this->fotografia(), 'Nenhuma versão ou marcador do outro estúdio pode ter sido tocado.');
+    }
+
+    public function test_token_invalido_ou_expirado_e_recusado(): void
+    {
+        $this->get(route('estudiomusica.portal.mostrar', ['token' => 'token-que-nao-existe']))->assertForbidden();
+
+        DB::table('clientes')
+            ->where('id', $this->a1['cliente']->id)
+            ->update(['token_portal_expira_em' => now()->subMinute()]);
+
+        $this->get(route('estudiomusica.portal.mostrar', ['token' => self::TOKEN]))->assertForbidden();
+    }
+
+    /**
+     * Todas as ações do portal que recebem um ID de versão.
+     *
+     * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
+     */
+    protected function acoesSobreUmaVersao(VersaoAudio $versao): array
+    {
+        $parametros = ['token' => self::TOKEN, 'versao' => $versao->id];
+
+        return [
+            ['GET', route('estudiomusica.portal.versoes.reproduzir', $parametros), []],
+            ['GET', route('estudiomusica.portal.versoes.descarregar', $parametros), []],
+            ['POST', route('estudiomusica.portal.versoes.comentar', $parametros), ['tempo_segundos' => 10, 'comentario' => 'Comentário do intruso']],
+            ['POST', route('estudiomusica.portal.versoes.aprovar', $parametros), []],
+            ['POST', route('estudiomusica.portal.versoes.ajustes', $parametros), ['comentario' => 'Ajustes do intruso']],
+        ];
+    }
+
+    protected function darTokenAoArtista(Cliente $cliente, string $token): void
+    {
+        DB::table('clientes')->where('id', $cliente->id)->update([
+            'token_portal' => hash('sha256', $token),
+            'token_portal_expira_em' => now()->addDay(),
+        ]);
+    }
+
+    /**
+     * Segundo artista no mesmo estúdio, com projeto, faixa e versão próprios.
+     *
+     * @param  array<string, mixed>  $estudio
+     * @return array<string, mixed>
+     */
+    protected function outroArtistaDoMesmoEstudio(array $estudio, string $marca): array
+    {
+        $empresaId = (int) $estudio['admin']->empresa_id;
+
+        $cliente = Cliente::create(['empresa_id' => $empresaId, 'nome' => "Artista {$marca}"]);
+        $projeto = ProjetoMusical::create([
+            'empresa_id' => $empresaId,
+            'cliente_id' => $cliente->id,
+            'nome' => "Projeto {$marca}",
+            'tipo_cobranca' => 'hora',
+        ]);
+        $faixa = FaixaMusical::create([
+            'empresa_id' => $empresaId,
+            'projeto_musical_id' => $projeto->id,
+            'nome' => "Faixa {$marca}",
+            'estado' => 'agendado',
+            'ordem' => 1,
+        ]);
+        $versao = VersaoAudio::create([
+            'empresa_id' => $empresaId,
+            'faixa_musical_id' => $faixa->id,
+            'rotulo' => "Versão {$marca}",
+            'caminho_ficheiro' => "estudio/{$marca}.mp3",
+            'estado' => 'pendente_aprovacao',
+            'enviada_em' => now(),
+        ]);
+
+        return compact('cliente', 'projeto', 'faixa', 'versao');
+    }
+
+    /** O estado das versões e dos marcadores de todas as empresas num instante. */
+    protected function fotografia(): array
+    {
+        return [
+            'versoes_audio' => DB::table('versoes_audio')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
+            'marcadores_audio' => DB::table('marcadores_audio')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
+            'notification_outbox' => DB::table('notification_outbox')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
+        ];
+    }
+}
diff --git a/Modules/EstudioMusica/tests/Support/CriaEstudioComDados.php b/Modules/EstudioMusica/tests/Support/CriaEstudioComDados.php
new file mode 100644
index 0000000..6172390
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Support/CriaEstudioComDados.php
@@ -0,0 +1,100 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Support;
+
+use Modules\Core\Models\User;
+use Modules\Core\Services\TenantManager;
+use Modules\EstudioMusica\Models\FaixaMusical;
+use Modules\EstudioMusica\Models\ProjetoMusical;
+use Modules\EstudioMusica\Models\Reclamacao;
+use Modules\EstudioMusica\Models\SalaEstudio;
+use Modules\EstudioMusica\Models\SessaoEstudio;
+use Modules\EstudioMusica\Models\SolicitacaoOrcamento;
+use Modules\EstudioMusica\Models\VersaoAudio;
+use Modules\Faturacao\Models\Cliente;
+
+/**
+ * Cria um estúdio completo — Administrador + um exemplar de cada recurso —
+ * para os testes de isolamento entre empresas. Todos os nomes levam a $marca
+ * (ex.: «SEGREDO-A»), para os testes poderem procurar essa marca numa página
+ * e provar que dados de uma empresa não aparecem noutra.
+ *
+ * Usa em conjunto com ComEstudioAutenticado (registarEstudio).
+ */
+trait CriaEstudioComDados
+{
+    /**
+     * @return array{admin: User, cliente: Cliente, sala: SalaEstudio, projeto: ProjetoMusical, faixa: FaixaMusical, versao: VersaoAudio, sessao: SessaoEstudio, solicitacao: SolicitacaoOrcamento, reclamacao: Reclamacao}
+     */
+    protected function criarEstudioComDados(string $nif, string $marca): array
+    {
+        $admin = $this->registarEstudio($nif);
+        $empresaId = (int) $admin->empresa_id;
+
+        // Os modelos levam empresa_id explícito e os testes leem sem scope:
+        // nada depende de um tenant que sobrou de outro passo.
+        app(TenantManager::class)->clear();
+
+        $cliente = Cliente::create(['empresa_id' => $empresaId, 'nome' => "Artista {$marca}"]);
+        $sala = SalaEstudio::create(['empresa_id' => $empresaId, 'nome' => "Sala {$marca}", 'preco_hora' => 5000]);
+
+        $projeto = ProjetoMusical::create([
+            'empresa_id' => $empresaId,
+            'cliente_id' => $cliente->id,
+            'nome' => "Projeto {$marca}",
+            'tipo_cobranca' => 'hora',
+            'percentual_sinal' => 40,
+        ]);
+
+        $faixa = FaixaMusical::create([
+            'empresa_id' => $empresaId,
+            'projeto_musical_id' => $projeto->id,
+            'nome' => "Faixa {$marca}",
+            'estado' => 'agendado',
+            'ordem' => 1,
+        ]);
+
+        $versao = VersaoAudio::create([
+            'empresa_id' => $empresaId,
+            'faixa_musical_id' => $faixa->id,
+            'rotulo' => "Versão {$marca}",
+            'caminho_ficheiro' => "estudio/{$marca}.mp3",
+            'estado' => 'pendente_aprovacao',
+            'enviada_em' => now(),
+        ]);
+
+        $inicio = now()->addDay()->startOfHour();
+        $sessao = new SessaoEstudio;
+        $sessao->forceFill([
+            'empresa_id' => $empresaId,
+            'sala_estudio_id' => $sala->id,
+            'cliente_id' => $cliente->id,
+            'projeto_musical_id' => $projeto->id,
+            'tipo_servico' => 'mixagem',
+            'inicio_previsto' => $inicio,
+            'fim_previsto' => $inicio->copy()->addHour(),
+            'estado' => 'agendada',
+        ])->save();
+
+        $solicitacao = SolicitacaoOrcamento::create([
+            'empresa_id' => $empresaId,
+            'nome' => "Pedido {$marca}",
+            'email' => strtolower("pedido-{$marca}@example.test"),
+            'tipo_servico_desejado' => 'mixagem',
+            'mensagem' => "Mensagem {$marca}",
+            'estado' => 'pendente',
+        ]);
+
+        $reclamacao = Reclamacao::create([
+            'empresa_id' => $empresaId,
+            'nome' => "Reclamante {$marca}",
+            'email' => strtolower("reclamante-{$marca}@example.test"),
+            'assunto' => "Assunto {$marca}",
+            'descricao' => "Descrição {$marca}",
+            'estado' => 'recebida',
+            'consentimento_dados_em' => now(),
+        ]);
+
+        return compact('admin', 'cliente', 'sala', 'projeto', 'faixa', 'versao', 'sessao', 'solicitacao', 'reclamacao');
+    }
+}
diff --git a/Modules/Faturacao/app/Contracts/FaturacaoGateway.php b/Modules/Faturacao/app/Contracts/FaturacaoGateway.php
index f9ba181..7b85955 100644
--- a/Modules/Faturacao/app/Contracts/FaturacaoGateway.php
+++ b/Modules/Faturacao/app/Contracts/FaturacaoGateway.php
@@ -17,7 +17,10 @@
 interface FaturacaoGateway
 {
     /**
-     * @param  array<int, array{produto_id?: int|null, descricao: string, quantidade: int|float, preco_unitario: int|float|string, preco_unitario_base?: int|float|string|null, taxa_iva: int|float|string, motivo_isencao?: string|null, operation_type?: string|null}>  $linhas
+     * Valores monetários e taxas: texto decimal («1234.50», «14») ou inteiro — nunca float.
+     * A quantidade pode ser inteira ou texto decimal («2.50»).
+     *
+     * @param  array<int, array{produto_id?: int|null, descricao: string, quantidade: int|string, preco_unitario: int|string, preco_unitario_base?: int|string|null, taxa_iva: int|string, motivo_isencao?: string|null, operation_type?: string|null}>  $linhas
      */
     public function criarRascunho(
         Empresa $empresa,
@@ -33,7 +36,7 @@ public function emitirFatura(Fatura $fatura): Fatura;
     public function emitirRecibo(
         int $empresaId,
         int $clienteId,
-        string|int|float $valor,
+        string|int $valor,
         string $meioPagamento,
         ?int $faturaId = null,
     ): Recibo;
diff --git a/Modules/Faturacao/app/Services/FaturacaoGatewayService.php b/Modules/Faturacao/app/Services/FaturacaoGatewayService.php
index 95c9b14..8e5c3f1 100644
--- a/Modules/Faturacao/app/Services/FaturacaoGatewayService.php
+++ b/Modules/Faturacao/app/Services/FaturacaoGatewayService.php
@@ -36,7 +36,7 @@ public function emitirFatura(Fatura $fatura): Fatura
     public function emitirRecibo(
         int $empresaId,
         int $clienteId,
-        string|int|float $valor,
+        string|int $valor,
         string $meioPagamento,
         ?int $faturaId = null,
     ): Recibo {
diff --git a/Modules/Faturacao/app/Support/Dinheiro.php b/Modules/Faturacao/app/Support/Dinheiro.php
index 0d2f1ca..8f2d7b0 100644
--- a/Modules/Faturacao/app/Support/Dinheiro.php
+++ b/Modules/Faturacao/app/Support/Dinheiro.php
@@ -19,6 +19,15 @@ public static function centimos(string|int|float|null $valor): int
         return self::escalar($valor, 2);
     }
 
+    /**
+     * Percentagem com 2 casas decimais em pontos base (33,33% → 3333; 40% → 4000),
+     * lida dígito a dígito: nunca multiplica um float por 100.
+     */
+    public static function pontosBase(string|int|float|null $percentagem): int
+    {
+        return self::escalar($percentagem, 2);
+    }
+
     /** Converte uma quantidade decimal em milésimos sem passar por float. */
     public static function milesimos(string|int|float|null $valor): int
     {
diff --git a/Modules/Faturacao/tests/Feature/GatewayFronteiraTest.php b/Modules/Faturacao/tests/Feature/GatewayFronteiraTest.php
new file mode 100644
index 0000000..e8cafe0
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/GatewayFronteiraTest.php
@@ -0,0 +1,208 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use DomainException;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\DB;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Contracts\FaturacaoGateway;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use PHPUnit\Framework\Attributes\DataProvider;
+use Tests\TestCase;
+
+/**
+ * O FaturacaoGateway é a fronteira que o Atelier e o Estúdio usam para faturar.
+ * Estes testes exercitam-no pelo seu comportamento (não só pela resolução no
+ * contentor): totais exatos, o mesmo resultado venha o valor em texto ou em
+ * float, pagamentos parciais, regularizações por nota de crédito e recusa de
+ * clientes ou faturas de outra empresa.
+ */
+class GatewayFronteiraTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
+        openssl_pkey_export($recurso, $pem);
+        $caminho = sys_get_temp_dir().'/faturacao-gateway-'.uniqid().'.pem';
+        file_put_contents($caminho, $pem);
+        config(['faturacao.chave_privada_path' => $caminho]);
+        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));
+    }
+
+    // ---------------------------------------------------------------------
+    // Valores exatos
+    // ---------------------------------------------------------------------
+
+    public function test_totais_pelo_gateway_sao_exatos_com_valores_em_texto(): void
+    {
+        [$empresa, $cliente] = $this->cenario('610000001');
+
+        $fatura = $this->emitirPeloGateway($empresa, $cliente, '19.99', '3', '14');
+
+        $this->assertSame('59.97', (string) $fatura->valor_sem_iva);
+        $this->assertSame('8.40', (string) $fatura->valor_iva);
+        $this->assertSame('68.37', (string) $fatura->valor_total);
+    }
+
+    public function test_iva_e_arredondado_por_excesso_ao_centimo(): void
+    {
+        [$empresa, $cliente] = $this->cenario('610000002');
+
+        // 3 × 0,10 = 0,30; IVA 14% = 0,042 → 0,05 (a AGT exige o arredondamento por excesso).
+        $fatura = $this->emitirPeloGateway($empresa, $cliente, '0.10', '3', '14');
+
+        $this->assertSame('0.30', (string) $fatura->valor_sem_iva);
+        $this->assertSame('0.05', (string) $fatura->valor_iva);
+        $this->assertSame('0.35', (string) $fatura->valor_total);
+    }
+
+    /**
+     * @return array<string, array{0: string, 1: string, 2: string}>
+     */
+    public static function valoresDificeis(): array
+    {
+        return [
+            'meio centimo no preço' => ['1.005', '1', '14'],
+            'meio centimo no total da linha' => ['33.335', '2', '14'],
+            'valor binário impreciso' => ['0.285', '7', '14'],
+            'o clássico 0,1 + 0,2' => ['0.10', '3', '14'],
+            'quantidade fracionária grande' => ['72060.70', '47.25', '14'],
+            'valor grande' => ['1234567.89', '1.5', '14'],
+        ];
+    }
+
+    #[DataProvider('valoresDificeis')]
+    public function test_o_mesmo_valor_em_texto_ou_em_float_da_o_mesmo_resultado(string $preco, string $quantidade, string $taxa): void
+    {
+        [$empresa, $cliente] = $this->cenario('610000003');
+
+        $emTexto = $this->emitirPeloGateway($empresa, $cliente, $preco, $quantidade, $taxa);
+        $emFloat = $this->emitirPeloGateway($empresa, $cliente, (float) $preco, (float) $quantidade, (float) $taxa);
+
+        $this->assertSame(
+            [(string) $emTexto->valor_sem_iva, (string) $emTexto->valor_iva, (string) $emTexto->valor_total],
+            [(string) $emFloat->valor_sem_iva, (string) $emFloat->valor_iva, (string) $emFloat->valor_total],
+            'O resultado não pode depender de o valor chegar como texto ou como float.'
+        );
+    }
+
+    // ---------------------------------------------------------------------
+    // Pagamentos parciais e regularizações
+    // ---------------------------------------------------------------------
+
+    public function test_pagamentos_parciais_atualizam_o_saldo_e_o_excesso_e_recusado(): void
+    {
+        [$empresa, $cliente] = $this->cenario('610000004');
+        $gateway = app(FaturacaoGateway::class);
+        $fatura = $this->emitirPeloGateway($empresa, $cliente, '1000.00', '1', '14'); // total 1140,00
+
+        $this->assertSame(114000, $gateway->saldoEmCentimos($fatura));
+
+        $gateway->emitirRecibo($empresa->id, $cliente->id, '400.00', 'dinheiro', $fatura->id);
+        $this->assertSame(['recebido' => 40000, 'creditado' => 0, 'saldo' => 74000], $gateway->resumoDaFatura($fatura->fresh()));
+
+        $gateway->emitirRecibo($empresa->id, $cliente->id, '740.00', 'transferencia', $fatura->id);
+        $this->assertSame(['recebido' => 114000, 'creditado' => 0, 'saldo' => 0], $gateway->resumoDaFatura($fatura->fresh()));
+
+        $this->assertRecusado(fn () => $gateway->emitirRecibo($empresa->id, $cliente->id, '0.01', 'dinheiro', $fatura->id));
+    }
+
+    public function test_nota_de_credito_parcial_reduz_o_saldo_visto_pelo_gateway(): void
+    {
+        [$empresa, $cliente] = $this->cenario('610000005');
+        $gateway = app(FaturacaoGateway::class);
+        $fatura = $this->emitirPeloGateway($empresa, $cliente, '1000.00', '1', '14'); // total 1140,00
+
+        $notas = app(NotaCreditoDebitoService::class);
+        $linha = [['descricao' => 'Desconto', 'quantidade' => 1, 'preco_unitario' => '500.00', 'taxa_iva' => '14']];
+        $notas->emitir($notas->criarRascunho($empresa->id, $cliente->id, 'credito', 'Desconto comercial', $linha, $fatura->id));
+
+        $this->assertSame(['recebido' => 0, 'creditado' => 57000, 'saldo' => 57000], $gateway->resumoDaFatura($fatura->fresh()));
+
+        $gateway->emitirRecibo($empresa->id, $cliente->id, '570.00', 'dinheiro', $fatura->id);
+        $this->assertSame(0, $gateway->saldoEmCentimos($fatura->fresh()));
+
+        $this->assertRecusado(fn () => $gateway->emitirRecibo($empresa->id, $cliente->id, '0.01', 'dinheiro', $fatura->id));
+    }
+
+    // ---------------------------------------------------------------------
+    // Isolamento entre empresas na fronteira
+    // ---------------------------------------------------------------------
+
+    public function test_gateway_recusa_clientes_e_faturas_de_outra_empresa_sem_deixar_rasto(): void
+    {
+        [$a, $clienteA] = $this->cenario('610000010');
+        [$b, $clienteB] = $this->cenario('610000011');
+        $gateway = app(FaturacaoGateway::class);
+        $faturaA = $this->emitirPeloGateway($a, $clienteA, '1000.00', '1', '14');
+        $antes = $this->estadoFiscal();
+
+        // Rascunho da empresa B para um cliente da empresa A.
+        $this->assertRecusado(fn () => $gateway->criarRascunho($b, $clienteA, [
+            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => '14'],
+        ]));
+
+        // Recibo da empresa B para um cliente da empresa A.
+        $this->assertRecusado(fn () => $gateway->emitirRecibo($b->id, $clienteA->id, '10.00', 'dinheiro'));
+
+        // Recibo da empresa B (cliente B) a liquidar uma fatura da empresa A.
+        $this->assertRecusado(fn () => $gateway->emitirRecibo($b->id, $clienteB->id, '10.00', 'dinheiro', $faturaA->id));
+
+        $this->assertSame($antes, $this->estadoFiscal(), 'Nenhum documento pode ter sido criado nem número consumido.');
+    }
+
+    // ---------------------------------------------------------------------
+    // Preparação
+    // ---------------------------------------------------------------------
+
+    /** @return array{0: Empresa, 1: Cliente} */
+    private function cenario(string $nif): array
+    {
+        $empresa = Empresa::create(['nome_comercial' => 'Loja '.$nif, 'nif' => $nif]);
+        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif, 'nif' => null]);
+
+        return [$empresa, $cliente];
+    }
+
+    private function emitirPeloGateway(Empresa $empresa, Cliente $cliente, string|int|float $preco, string|int|float $quantidade, string|int|float $taxa): Fatura
+    {
+        $gateway = app(FaturacaoGateway::class);
+
+        return $gateway->emitirFatura($gateway->criarRascunho($empresa, $cliente, [[
+            'descricao' => 'Serviço',
+            'quantidade' => $quantidade,
+            'preco_unitario' => $preco,
+            'taxa_iva' => $taxa,
+        ]]));
+    }
+
+    private function assertRecusado(callable $acao): void
+    {
+        try {
+            $acao();
+        } catch (DomainException) {
+            $this->addToAssertionCount(1);
+
+            return;
+        }
+
+        $this->fail('O gateway devia ter recusado esta operação com uma DomainException.');
+    }
+
+    /** Documentos e numeração de todas as empresas num instante. */
+    private function estadoFiscal(): array
+    {
+        return [
+            'faturas' => DB::table('faturas')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
+            'recibos' => DB::table('recibos')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
+            'series' => DB::table('series')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
+        ];
+    }
+}
__FIM_DO_PATCH__
}

# commitar <mensagem> <ficheiros...>: commit só desses ficheiros; sem alterações, não faz nada.
commitar() {
  local titulo="$1"; shift
  git add -- "$@"
  if git diff --cached --quiet -- "$@"; then
    ok "Já commitado: $titulo"
  else
    git commit --quiet -F - -- "$@"
    ok "Commit: $(git log -1 --format='%h %s')"
  fi
}

# ---------------------------------------------------------------------------
passo "1/6 · Verificar o ambiente"

RAIZ="$(git rev-parse --show-toplevel 2>/dev/null)" || falha "Corre este script dentro do repositório Saas1."
cd "$RAIZ"
[[ -f artisan && -d Modules/Atelier ]] || falha "Isto não parece ser a raiz do Saas1 (falta o artisan ou o Modules/Atelier)."

RAMO_ATUAL="$(git rev-parse --abbrev-ref HEAD)"
[[ "$RAMO_ATUAL" == "$RAMO" ]] || falha "Estás no ramo «$RAMO_ATUAL». Muda primeiro para o da PR: git switch $RAMO"

command -v php >/dev/null || falha "PHP não encontrado."
if [[ ! -f vendor/autoload.php ]]; then
  command -v composer >/dev/null || falha "Composer não encontrado."
  aviso "A instalar as dependências (composer install)…"
  composer install --no-interaction --prefer-dist --no-progress
fi
if [[ ! -f .env ]]; then
  cp .env.example .env
  php artisan key:generate --ansi
  aviso ".env criado a partir do .env.example"
fi
ok "Ramo $RAMO · PHP $(php -r 'echo PHP_VERSION;')"

# ---------------------------------------------------------------------------
passo "2/6 · Sincronizar com o GitHub"

git fetch origin "$RAMO" main --quiet
LOCAL="$(git rev-parse HEAD)"
REMOTO="$(git rev-parse "origin/$RAMO")"

if [[ "$LOCAL" == "$REMOTO" ]]; then
  ok "Ramo local igual ao remoto (${LOCAL:0:7})"
elif git merge-base --is-ancestor "$LOCAL" "$REMOTO"; then
  git merge --ff-only --quiet "origin/$RAMO" || falha "Não consegui actualizar o ramo local. Faz: git pull --ff-only"
  ok "Ramo local actualizado para ${REMOTO:0:7}"
elif git merge-base --is-ancestor "$REMOTO" "$LOCAL"; then
  aviso "Tens commits locais ainda não enviados; vão seguir no push."
else
  falha "O ramo local e o remoto divergiram. Resolve primeiro (git pull --rebase) e repete."
fi

# ---------------------------------------------------------------------------
passo "3/6 · Aplicar as correções e os testes"

patch_correcoes > "$PATCH"

if git apply --reverse --check "$PATCH" 2>/dev/null; then
  ok "As correções já estão aplicadas nesta cópia."
elif git apply --check "$PATCH" 2>/dev/null; then
  if ! git diff --quiet HEAD -- "${TODOS_OS_FICHEIROS[@]}"; then
    git status --short -- "${TODOS_OS_FICHEIROS[@]}"
    falha "Tens alterações locais nestes ficheiros. Faz commit ou git stash e repete."
  fi
  git apply --index "$PATCH"
  ok "Correções aplicadas:"
  git apply --stat "$PATCH" | sed 's/^/      /'
else
  git apply --check "$PATCH" || true
  falha "O patch não encaixa: o ramo mudou desde que foi preparado. Envia-me o erro acima e eu adapto."
fi

# ---------------------------------------------------------------------------
passo "4/6 · Validar como o CI (Pint em todos os ficheiros PHP da PR)"

vendor/bin/pint "${TODOS_OS_FICHEIROS[@]}" >/dev/null || true
BASE="$(git merge-base origin/main HEAD)"
mapfile -t PHP_ALTERADOS < <(git diff --name-only --diff-filter=d "$BASE" -- '*.php')
vendor/bin/pint --test "${PHP_ALTERADOS[@]}" || falha "O Pint ainda encontra problemas (ver acima). Nada foi commitado."
ok "Pint: ${#PHP_ALTERADOS[@]} ficheiros PHP sem problemas"

# ---------------------------------------------------------------------------
passo "5/6 · Testes"

php artisan config:clear --ansi >/dev/null 2>&1 || true
php artisan test "${TESTES_NOVOS[@]}"
ok "Testes novos: OK"

if [[ "${SEM_TESTES:-0}" == "1" ]]; then
  aviso "SEM_TESTES=1: suite completa saltada (o CI vai corrê-la)."
else
  php artisan test --exclude-group=auditoria
  ok "Suite completa: OK"
fi

# ---------------------------------------------------------------------------
passo "6/6 · Commits e envio para a PR #$PR_NUMERO"

commitar "testes de isolamento" "${FICHEIROS_ISOLAMENTO[@]}" <<'__FIM_DA_MENSAGEM__'
Acrescenta testes de isolamento multi-tenant do Estúdio e do portal

Estúdio (equipa): o administrador de um estúdio recebe 404 em 22 rotas com ID
de outro estúdio e a base de dados fica exatamente igual; as listagens não
mostram dados alheios; não atribui nem remove papéis a utilizadores de outro
estúdio nem cria projetos/sessões com clientes, salas ou projetos alheios.

Portal (token): o token de um artista não alcança versões de outro artista do
mesmo estúdio (403) nem de outro estúdio (404); tokens inválidos ou expirados
são recusados.

Verificado por mutação: remover o scope de tenant de um controlador, ou a
verificação de dono no portal, faz falhar estes testes.
__FIM_DA_MENSAGEM__

commitar "Outbox" "${FICHEIROS_OUTBOX[@]}" <<'__FIM_DA_MENSAGEM__'
Corrige envios duplicados e estados incoerentes na Outbox de notificações

NotificationProcessor:
- «accepted» não é terminal, por isso um segundo job (o original e o
  reagendado pelo recuperador) reenviava uma mensagem já aceite pelo
  fornecedor, ou convertia-a em dead letter no limite de tentativas. Agora
  accepted/delivered/read nunca voltam a ser enviadas.
- O sinal NotificationStateChanged passa a ser emitido depois do commit e sem
  poder desfazê-lo: um listener de outro módulo a falhar já não reverte o
  estado aceite pelo fornecedor (o que levava o recuperador a reenviar).
- As tentativas perdidas (lease expirado) ficam «abandoned» com o código
  lease_expired — resultado desconhecido — em vez de «processing» para sempre.
- O lease passa a ser a constante LEASE_SEGUNDOS, partilhada com o recuperador.

notifications:recover regista em log os leases abandonados recuperados e as
notificações em dead letter nas últimas 24 horas.

Testes: um worker antigo nunca sobrepõe a tentativa já recuperada (já estava
correto), leases ativos/expirados, recuperação, limite de tentativas, o
timeout do fornecedor caber dentro do lease e isolamento entre empresas.
__FIM_DA_MENSAGEM__

commitar "dinheiro" "${FICHEIROS_DINHEIRO[@]}" <<'__FIM_DA_MENSAGEM__'
Remove o float da fronteira monetária e testa o FaturacaoGateway

- O contrato do FaturacaoGateway deixa de aceitar float em valores e taxas
  (texto decimal ou inteiro).
- Atelier e Estúdio passam os decimais como texto, sem «(float)»; o valor do
  sinal e do recibo segue como texto desde o controlador.
- Dinheiro::pontosBase() lê percentagens dígito a dígito (sem float × 100).
- PedidoMaterial::valor_total em aritmética exata e half up, igual à fatura: o
  cálculo em float errava um cêntimo em ~0,4 % dos casos (47,25 × 72 060,70 =
  3 404 868,075 saía 3 404 868,07).
- ProjetoMusicalController: import reordenado (o Pint exige-o assim que o
  ficheiro entra na PR).

Testes do gateway: totais exatos, mesmo resultado com texto ou float, IVA
arredondado por excesso, pagamentos parciais e excesso recusado, nota de
crédito parcial e recusa de clientes e faturas de outra empresa sem deixar
rasto nem consumir numeração.
__FIM_DA_MENSAGEM__

if [[ "${SEM_PUSH:-0}" == "1" ]]; then
  aviso "SEM_PUSH=1: os commits ficaram só locais. Para enviar: git push origin $RAMO"
else
  git push origin "$RAMO" || falha "O push foi recusado. Os commits estão feitos localmente; resolve e faz: git push origin $RAMO"
  ok "Push concluído"
fi

printf '\n%sConcluído.%s A PR continua em Draft e a main não foi tocada.\n' "$NEGRITO" "$FIM"
printf '  • Acompanha o CI: %s  (ou: gh pr checks %s --watch)\n' "$PR_URL" "$PR_NUMERO"
printf '  • Esperado: «qualidade» e «testes-mysql» a verde.\n'
