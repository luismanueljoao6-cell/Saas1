#!/usr/bin/env bash
# =============================================================================
# corrigir-pr33-v2.sh — segunda ronda de correções da PR #33
#                       (branch feat/faturacao-fiscal-core, base 107cdea)
#
#   1. Reenvio de campanhas: um pedido recusado (reenvio já em curso, campanha
#      cancelada, sem envios falhados) volta à página com uma mensagem em vez
#      de uma página de erro 409/422. Num clique duplo o navegador mostra a
#      resposta do SEGUNDO pedido: o utilizador via um erro mesmo com o
#      primeiro aceite. Os testes foram atualizados (inclui a rota real).
#   2. Remove 'corrigir-pr33.sh' do repositório: era um script de utilização
#      única e foi parar à PR por engano (commit "f").
#
# Uso (na raiz do projeto, com a branch da PR em checkout):
#     bash corrigir-pr33-v2.sh           # aplica, valida e faz commits locais
#     bash corrigir-pr33-v2.sh --push    # idem + git push (sem --force)
#
# O próprio script pode estar dentro da pasta do projeto: nunca é adicionado
# ao git (usa "git apply --index", que só trata dos ficheiros do patch).
# Nunca toca na main, nunca faz merge, nunca faz force-push. É idempotente.
# =============================================================================
set -euo pipefail

RAMO_ESPERADO="feat/faturacao-fiscal-core"
COMMIT_BASE="107cdea"
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

# --- 1. Patch ------------------------------------------------------------------
cat > "$TMP/01-ux-reenvio.patch" <<'PATCH_UX_REENVIO'
diff --git a/Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php b/Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php
index 2a13624..81ddb87 100644
--- a/Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php
+++ b/Modules/Atelier/app/Http/Controllers/Admin/CampanhaController.php
@@ -72,39 +72,46 @@ public function enviarAgora(Request $request, int $campanha): RedirectResponse
 
         // O pedido é validado E registado na mesma transação, com lock de linha
         // na campanha: dois pedidos simultâneos são serializados aqui e só o
-        // primeiro fica com o reenvio (o segundo recebe 409). O job nunca decide
-        // sozinho se há reenvio — só corre se levar o token deste pedido.
-        [$campanha, $pedido] = DB::transaction(function () use ($request, $campanha): array {
+        // primeiro fica com o reenvio. O job nunca decide sozinho se há reenvio
+        // — só corre se levar o token deste pedido.
+        //
+        // Os pedidos recusados voltam à página com uma mensagem (nunca uma
+        // página de erro): num clique duplo o navegador mostra a resposta do
+        // SEGUNDO pedido, e o utilizador não pode ver um erro quando o primeiro
+        // foi aceite.
+        [$campanha, $pedido, $recusa] = DB::transaction(function () use ($request, $campanha): array {
             $campanha = Campanha::query()->lockForUpdate()->findOrFail($campanha);
 
-            abort_if($campanha->estado === 'cancelada', 422, 'Esta campanha foi cancelada.');
+            if ($campanha->estado === 'cancelada') {
+                return [$campanha, null, ['erro', 'Esta campanha foi cancelada.']];
+            }
 
             $reenvio = in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true);
             $temFalhas = $campanha->envios()->where('estado', CampanhaEnvio::ESTADO_FALHOU)->exists();
 
-            abort_if(
-                ! ($reenvio || in_array($campanha->estado, ['rascunho', 'agendada'], true))
-                    || ($reenvio && ! $temFalhas),
-                422,
-                'Esta campanha não pode ser enviada ou não tem envios falhados para repetir.'
-            );
+            if (! ($reenvio || in_array($campanha->estado, ['rascunho', 'agendada'], true))
+                || ($reenvio && ! $temFalhas)) {
+                return [$campanha, null, ['erro', 'Esta campanha não pode ser enviada ou não tem envios falhados para repetir.']];
+            }
 
             // Primeiro envio: o lock por campanha e a idempotência dos envios
             // já tornam um clique duplo inofensivo.
             if (! $reenvio) {
-                return [$campanha, null];
+                return [$campanha, null, null];
             }
 
             CampanhaReenvio::expirarAbandonados($campanha->id);
 
-            abort_if(
-                CampanhaReenvio::query()->where('campanha_id', $campanha->id)->where('aberto', true)->exists(),
-                409,
-                'Já existe um reenvio em curso para esta campanha.'
-            );
+            $emCurso = ['sucesso', 'Já existe um reenvio em curso para esta campanha — os estados vão aparecer aqui à medida que forem processados.'];
+
+            if (CampanhaReenvio::query()->where('campanha_id', $campanha->id)->where('aberto', true)->exists()) {
+                return [$campanha, null, $emCurso];
+            }
 
             try {
-                $pedido = CampanhaReenvio::create([
+                // Savepoint próprio: se a unique (campanha_id, aberto) disparar,
+                // só esta inserção é desfeita e a transação externa continua válida.
+                $pedido = DB::transaction(fn () => CampanhaReenvio::create([
                     'empresa_id' => $campanha->empresa_id,
                     'campanha_id' => $campanha->id,
                     'token' => (string) Str::uuid(),
@@ -112,16 +119,19 @@ public function enviarAgora(Request $request, int $campanha): RedirectResponse
                     'aberto' => true,
                     'solicitado_por_id' => $request->user()->id,
                     'solicitado_em' => now(),
-                ]);
+                ]));
             } catch (UniqueConstraintViolationException) {
-                // Rede de segurança da unique (campanha_id, aberto) caso o lock
-                // de linha não esteja disponível no driver.
-                abort(409, 'Já existe um reenvio em curso para esta campanha.');
+                // Rede de segurança caso o lock de linha não esteja disponível no driver.
+                return [$campanha, null, $emCurso];
             }
 
-            return [$campanha, $pedido];
+            return [$campanha, $pedido, null];
         });
 
+        if ($recusa !== null) {
+            return back()->with($recusa[0], $recusa[1]);
+        }
+
         try {
             DispararCampanhaJob::dispatch($campanha->id, $pedido?->token);
         } catch (Throwable $e) {
diff --git a/Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php b/Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php
index 852e7ba..8e6e3be 100644
--- a/Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php
+++ b/Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php
@@ -4,6 +4,7 @@
 
 use Illuminate\Database\UniqueConstraintViolationException;
 use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Cache;
 use Illuminate\Support\Facades\Queue;
@@ -25,7 +26,6 @@
 use RuntimeException;
 use Spatie\Permission\Models\Role;
 use Spatie\Permission\PermissionRegistrar;
-use Symfony\Component\HttpKernel\Exception\HttpException;
 use Tests\TestCase;
 
 /**
@@ -92,12 +92,12 @@ protected function setUp(): void
         ]);
     }
 
-    protected function pedirReenvio(): void
+    protected function pedirReenvio(): RedirectResponse
     {
         $request = Request::create('/', 'POST');
         $request->setUserResolver(fn () => $this->admin);
 
-        app(CampanhaController::class)->enviarAgora($request, $this->campanha->id);
+        return app(CampanhaController::class)->enviarAgora($request, $this->campanha->id);
     }
 
     protected function executar(?string $token): void
@@ -143,14 +143,14 @@ public function test_dois_pedidos_simultaneos_aceitam_apenas_o_primeiro(): void
     {
         Queue::fake();
 
-        $this->pedirReenvio();
+        $primeiro = $this->pedirReenvio();
+        $this->assertStringContainsString('Envio iniciado', (string) $primeiro->getSession()->get('sucesso'));
 
-        try {
-            $this->pedirReenvio();
-            $this->fail('O segundo pedido tinha de ser recusado enquanto o primeiro está aberto.');
-        } catch (HttpException $e) {
-            $this->assertSame(409, $e->getStatusCode());
-        }
+        // O segundo pedido não cria outro reenvio e volta com uma mensagem
+        // (nunca um erro: num clique duplo é a resposta que o utilizador vê).
+        $segundo = $this->pedirReenvio();
+        $this->assertStringContainsString('Já existe um reenvio em curso', (string) $segundo->getSession()->get('sucesso'));
+        $this->assertNull($segundo->getSession()->get('erro'));
 
         Queue::assertPushed(DispararCampanhaJob::class, 1);
         $this->assertSame(1, CampanhaReenvio::query()->where('campanha_id', $this->campanha->id)->count());
@@ -332,13 +332,9 @@ public function test_campanha_sem_envios_falhados_nao_aceita_reenvio(): void
         Queue::fake();
         $this->envio->update(['estado' => CampanhaEnvio::ESTADO_ENVIADO]);
 
-        try {
-            $this->pedirReenvio();
-            $this->fail('Sem envios falhados não há nada para repetir.');
-        } catch (HttpException $e) {
-            $this->assertSame(422, $e->getStatusCode());
-        }
+        $resposta = $this->pedirReenvio();
 
+        $this->assertStringContainsString('não tem envios falhados', (string) $resposta->getSession()->get('erro'));
         Queue::assertNothingPushed();
         $this->assertSame(0, CampanhaReenvio::query()->count());
     }
diff --git a/Modules/Atelier/tests/Feature/ReenvioCampanhaHttpTest.php b/Modules/Atelier/tests/Feature/ReenvioCampanhaHttpTest.php
index 4981e4e..07f3611 100644
--- a/Modules/Atelier/tests/Feature/ReenvioCampanhaHttpTest.php
+++ b/Modules/Atelier/tests/Feature/ReenvioCampanhaHttpTest.php
@@ -18,7 +18,7 @@ class ReenvioCampanhaHttpTest extends TestCase
 {
     use RefreshDatabase;
 
-    public function test_clique_duplo_na_rota_real_aceita_um_pedido_e_recusa_o_segundo(): void
+    public function test_clique_duplo_na_rota_real_aceita_um_pedido_e_o_segundo_volta_com_aviso(): void
     {
         $this->withoutVite();
         Queue::fake();
@@ -50,7 +50,11 @@ public function test_clique_duplo_na_rota_real_aceita_um_pedido_e_recusa_o_segun
         $rota = route('atelier.campanhas.enviar-agora', $campanha->id);
 
         $this->actingAs($admin)->post($rota)->assertRedirect()->assertSessionHas('sucesso');
-        $this->actingAs($admin)->post($rota)->assertStatus(409);
+        // O segundo clique volta à página com uma mensagem, não com uma página de erro.
+        $this->actingAs($admin)->post($rota)
+            ->assertRedirect()
+            ->assertSessionHas('sucesso', fn (string $m) => str_contains($m, 'Já existe um reenvio em curso'))
+            ->assertSessionMissing('erro');
 
         app(TenantManager::class)->set((int) $admin->empresa_id);
         Queue::assertPushed(DispararCampanhaJob::class, 1);
PATCH_UX_REENVIO

MSG_UX="fix(atelier): pedidos de reenvio recusados voltam com mensagem em vez de erro 409/422"
MSG_RM="chore: remover corrigir-pr33.sh do repositório (script de utilização única)"

if git log --format=%s "$COMMIT_BASE"..HEAD | grep -Fxq -- "$MSG_UX"; then
  info "já aplicado, a saltar: $MSG_UX"
else
  git apply --check "$TMP/01-ux-reenvio.patch" 2>/dev/null \
    || erro "o patch não aplica limpo (a branch mudou nestes ficheiros?)."
  git apply --index "$TMP/01-ux-reenvio.patch"
  git commit -q -m "$MSG_UX"
  info "commit: $MSG_UX"
fi

# --- 2. Remover o script antigo do repositório --------------------------------
if git ls-files --error-unmatch corrigir-pr33.sh >/dev/null 2>&1; then
  git rm -q corrigir-pr33.sh
  git commit -q -m "$MSG_RM"
  info "commit: $MSG_RM"
else
  info "corrigir-pr33.sh já não está no repositório, a saltar."
fi

# --- 3. Validação local (se as dependências estiverem instaladas) --------------
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
  info "Testes do reenvio de campanhas"
  php artisan test Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php \
                   Modules/Atelier/tests/Feature/ReenvioCampanhaHttpTest.php \
                   Modules/Atelier/tests/Feature/ReenvioCampanhaRegrasTest.php \
    || erro "os testes de reenvio falharam — não faças push."
  info "Suite completa (como no CI, sem o grupo auditoria)"
  php artisan test --exclude-group=auditoria || erro "a suite falhou — não faças push."
else
  echo "AVISO: vendor/ não existe; testes saltados."
fi

# --- 4. Push (opcional) --------------------------------------------------------
if ((PUSH)); then
  info "git push origin $RAMO_ESPERADO"
  git push origin "$RAMO_ESPERADO" || erro "o push falhou (a branch avançou no GitHub?). Faz: git pull --rebase origin $RAMO_ESPERADO  e repete."
  info "Feito. Acompanha o CI da PR #33 (qualidade + testes-mysql)."
else
  echo
  info "Concluído localmente. Quando quiseres enviar:  git push origin $RAMO_ESPERADO"
fi
