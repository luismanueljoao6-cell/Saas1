#!/usr/bin/env bash
# PR #33 — Fase 8: integridade multiempresa e permissões
# Correr na raiz do repositório, na branch feat/faturacao-fiscal-core.
set -euo pipefail

[ "$(git rev-parse --abbrev-ref HEAD)" = "feat/faturacao-fiscal-core" ] || { echo "Muda para feat/faturacao-fiscal-core"; exit 1; }
git pull --ff-only
[ -z "$(git status --porcelain)" ] || { echo "Working tree com alterações; faz commit/stash primeiro."; exit 1; }

cat > /tmp/fase8.patch <<'PATCH_EOF'
diff --git a/Modules/Atelier/app/Console/EliminarDadosClienteCommand.php b/Modules/Atelier/app/Console/EliminarDadosClienteCommand.php
index 4ac8c22..b645de9 100644
--- a/Modules/Atelier/app/Console/EliminarDadosClienteCommand.php
+++ b/Modules/Atelier/app/Console/EliminarDadosClienteCommand.php
@@ -58,7 +58,7 @@ public function handle(): int
             DB::table('atelier_pedido_fotos')->whereIn('pedido_id', $pedidoIds)->delete();
             DB::table('atelier_medidas')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->delete();
             DB::table('atelier_clientes_crm')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->delete();
-            DB::table('atelier_campanha_envios')->where('cliente_id', $clienteId)->delete();
+            DB::table('atelier_campanha_envios')->where('cliente_id', $clienteId)->whereIn('campanha_id', DB::table('atelier_campanhas')->where('empresa_id', $empresaId)->select('id'))->delete();
         });
 
         foreach ($caminhosFotos as $caminho) {
diff --git a/Modules/Atelier/app/Console/ExportarDadosClienteCommand.php b/Modules/Atelier/app/Console/ExportarDadosClienteCommand.php
index a17a4ed..d93f5f1 100644
--- a/Modules/Atelier/app/Console/ExportarDadosClienteCommand.php
+++ b/Modules/Atelier/app/Console/ExportarDadosClienteCommand.php
@@ -46,7 +46,7 @@ public function handle(): int
             'pedidos' => $pedidos,
             'fotos' => DB::table('atelier_pedido_fotos')->whereIn('pedido_id', $pedidoIds)->get(),
             'provas' => DB::table('atelier_provas')->whereIn('pedido_id', $pedidoIds)->get(),
-            'envios_de_campanhas' => DB::table('atelier_campanha_envios')->where('cliente_id', $clienteId)->get(),
+            'envios_de_campanhas' => DB::table('atelier_campanha_envios')->where('cliente_id', $clienteId)->whereIn('campanha_id', DB::table('atelier_campanhas')->where('empresa_id', $empresaId)->select('id'))->get(),
         ];
 
         $this->line(json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
diff --git a/Modules/Core/app/Http/Controllers/UtilizadorController.php b/Modules/Core/app/Http/Controllers/UtilizadorController.php
index e02d87b..4e2d223 100644
--- a/Modules/Core/app/Http/Controllers/UtilizadorController.php
+++ b/Modules/Core/app/Http/Controllers/UtilizadorController.php
@@ -161,17 +161,24 @@ protected function mensagemSeLimiteAtingido(Empresa $empresa): ?string
 
     protected function atribuirPapel(User $utilizador, int $empresaId, bool $administrador): void
     {
-        app(PermissionRegistrar::class)->setPermissionsTeamId($empresaId);
-
-        // 'empresa_id' explícito: sem ele o firstOrCreate procura o papel
-        // pelo nome em TODAS as empresas e reutilizaria o de outra.
-        $papel = Role::firstOrCreate([
-            'name' => $administrador ? 'Administrador' : 'Utilizador',
-            'guard_name' => 'web',
-            'empresa_id' => $empresaId,
-        ]);
+        $registrar = app(PermissionRegistrar::class);
+        $equipaAnterior = $registrar->getPermissionsTeamId();
+        $registrar->setPermissionsTeamId($empresaId);
 
-        $utilizador->assignRole($papel);
+        try {
+            // 'empresa_id' explícito: sem ele o firstOrCreate procura o papel
+            // pelo nome em TODAS as empresas e reutilizaria o de outra.
+            $papel = Role::firstOrCreate([
+                'name' => $administrador ? 'Administrador' : 'Utilizador',
+                'guard_name' => 'web',
+                'empresa_id' => $empresaId,
+            ]);
+
+            $utilizador->assignRole($papel);
+        } finally {
+            // Num worker ou comando persistente a equipa não pode ficar presa.
+            $registrar->setPermissionsTeamId($equipaAnterior);
+        }
     }
 
     protected function garantirAdministrador(User $utilizador): void
diff --git a/Modules/Core/app/Http/Middleware/NegarPapeis.php b/Modules/Core/app/Http/Middleware/NegarPapeis.php
new file mode 100644
index 0000000..2f75029
--- /dev/null
+++ b/Modules/Core/app/Http/Middleware/NegarPapeis.php
@@ -0,0 +1,35 @@
+<?php
+
+namespace Modules\Core\Http\Middleware;
+
+use Closure;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+
+/**
+ * Complemento de «papel»: recusa quem tem algum dos papéis indicados, a não ser
+ * que também seja Administrador. Serve para áreas que os papéis especializados
+ * nunca devem alcançar por URL direto, mesmo com o serviço ativo na empresa
+ * (ex.: uma Costureira só vê pedidos que lhe estão atribuídos; um Engenheiro de
+ * estúdio não tem permissão de cobrança). Utilizadores sem papéis especializados
+ * não são afetados.
+ *
+ * Tem de correr DEPOIS de 'tenant': os papéis do Spatie dependem da equipa.
+ */
+class NegarPapeis
+{
+    public function handle(Request $request, Closure $next, string ...$papeis): Response
+    {
+        $utilizador = $request->user();
+
+        abort_if(
+            $utilizador
+                && ! $utilizador->hasRole('Administrador')
+                && $utilizador->hasAnyRole($papeis),
+            403,
+            'O teu papel não dá acesso a esta área.'
+        );
+
+        return $next($request);
+    }
+}
diff --git a/Modules/Core/app/Providers/CoreServiceProvider.php b/Modules/Core/app/Providers/CoreServiceProvider.php
index fd80809..271d9bd 100644
--- a/Modules/Core/app/Providers/CoreServiceProvider.php
+++ b/Modules/Core/app/Providers/CoreServiceProvider.php
@@ -16,6 +16,7 @@
 use Modules\Core\Http\Middleware\ExigirServico;
 use Modules\Core\Http\Middleware\ExigirServicoDaEmpresa;
 use Modules\Core\Http\Middleware\IdentificarTenant;
+use Modules\Core\Http\Middleware\NegarPapeis;
 use Modules\Core\Http\Middleware\VerificarSubscricaoAtiva;
 use Modules\Core\Notifications\NotificationChannelRegistry;
 use Modules\Core\Repositories\Contracts\EmpresaRepositoryInterface;
@@ -109,6 +110,7 @@ protected function registerMiddlewareAliases(): void
         $router->aliasMiddleware('tenant', IdentificarTenant::class);
         $router->aliasMiddleware('subscricao.ativa', VerificarSubscricaoAtiva::class);
         $router->aliasMiddleware('papel', ExigirPapel::class);
+        $router->aliasMiddleware('papel.nao', NegarPapeis::class);
         $router->aliasMiddleware('servico', ExigirServico::class);
         $router->aliasMiddleware('servico.empresa', ExigirServicoDaEmpresa::class);
     }
diff --git a/Modules/Faturacao/routes/web.php b/Modules/Faturacao/routes/web.php
index 2fe0d8f..b1177dc 100644
--- a/Modules/Faturacao/routes/web.php
+++ b/Modules/Faturacao/routes/web.php
@@ -13,7 +13,7 @@
 | (emitir, SAF-T) exigem o papel Administrador ('papel' corre DEPOIS de
 | 'tenant'). Durante o grace period, a emissão é bloqueada no controller.
 */
-Route::middleware(['auth', 'tenant', 'subscricao.ativa', 'servico:faturacao'])
+Route::middleware(['auth', 'tenant', 'subscricao.ativa', 'servico:faturacao', 'papel.nao:Costureira,Engenheiro'])
     ->prefix('faturacao')
     ->name('faturacao.')
     ->group(function () {
diff --git a/Modules/Faturacao/tests/Feature/IsolamentoEPermissoesFaturacaoTest.php b/Modules/Faturacao/tests/Feature/IsolamentoEPermissoesFaturacaoTest.php
new file mode 100644
index 0000000..005a25e
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/IsolamentoEPermissoesFaturacaoTest.php
@@ -0,0 +1,168 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Storage;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Models\User;
+use Modules\Core\Support\Servico;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\Recibo;
+use Modules\Faturacao\Services\FaturaService;
+use Spatie\Permission\Models\Role;
+use Spatie\Permission\PermissionRegistrar;
+use Tests\TestCase;
+
+/**
+ * Isolamento entre empresas e papéis restritos na Faturação: o administrador
+ * de uma empresa nunca alcança documentos, recibos ou ficheiros SAF-T de outra,
+ * e papéis especializados (Costureira, Engenheiro) não entram na Faturação.
+ */
+class IsolamentoEPermissoesFaturacaoTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresaA;
+
+    protected Empresa $empresaB;
+
+    protected Cliente $clienteA;
+
+    protected User $adminA;
+
+    protected User $adminB;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->withoutVite();
+
+        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
+        openssl_pkey_export($recurso, $pem);
+        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
+        file_put_contents($caminho, $pem);
+        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));
+
+        config(['faturacao.chave_privada_path' => $caminho, 'faturacao.agt.ativo' => false]);
+
+        $this->empresaA = $this->criarEmpresa('Loja A', '5001636863');
+        $this->empresaB = $this->criarEmpresa('Loja B', '5001636864');
+        $this->clienteA = Cliente::create(['empresa_id' => $this->empresaA->id, 'nome' => 'Cliente A', 'nif' => null]);
+        $this->adminA = $this->criarUtilizador($this->empresaA, 'admin@a.ao', 'Administrador');
+        $this->adminB = $this->criarUtilizador($this->empresaB, 'admin@b.ao', 'Administrador');
+    }
+
+    protected function criarEmpresa(string $nome, string $nif): Empresa
+    {
+        $empresa = Empresa::create(['nome_comercial' => $nome, 'nif' => $nif]);
+        $empresa->aderirServicos([Servico::Faturacao]);
+
+        return $empresa;
+    }
+
+    protected function criarUtilizador(Empresa $empresa, string $email, ?string $papel): User
+    {
+        $utilizador = User::create(['empresa_id' => $empresa->id, 'name' => $email, 'email' => $email, 'password' => 'Senha-Forte-123!']);
+
+        if ($papel !== null) {
+            $registrar = app(PermissionRegistrar::class);
+            $registrar->setPermissionsTeamId($empresa->id);
+            $utilizador->assignRole(Role::firstOrCreate(['name' => $papel, 'guard_name' => 'web', 'empresa_id' => $empresa->id]));
+            $registrar->setPermissionsTeamId(null);
+            $registrar->forgetCachedPermissions();
+        }
+
+        return $utilizador;
+    }
+
+    protected function rascunhoDaEmpresaA(): Fatura
+    {
+        return app(FaturaService::class)->criarRascunho($this->empresaA, $this->clienteA, [
+            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
+        ]);
+    }
+
+    public function test_administrador_de_outra_empresa_nao_alcanca_a_fatura(): void
+    {
+        $fatura = $this->rascunhoDaEmpresaA();
+
+        $this->actingAs($this->adminB)->get(route('faturacao.faturas.mostrar', $fatura->id))->assertNotFound();
+        $this->actingAs($this->adminB)->get(route('faturacao.faturas.editar', $fatura->id))->assertNotFound();
+        $this->actingAs($this->adminB)->delete(route('faturacao.faturas.eliminar', $fatura->id))->assertNotFound();
+        $this->actingAs($this->adminB)->post(route('faturacao.faturas.emitir', $fatura->id))->assertNotFound();
+
+        $fatura->refresh();
+        $this->assertSame('rascunho', $fatura->estado);
+    }
+
+    public function test_nao_se_cria_fatura_com_cliente_de_outra_empresa(): void
+    {
+        $this->actingAs($this->adminB)
+            ->post(route('faturacao.faturas.guardar'), [
+                'cliente_id' => $this->clienteA->id,
+                'linhas' => [['descricao' => 'X', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => 14]],
+            ])
+            ->assertSessionHasErrors(['cliente_id']);
+
+        $this->assertSame(0, Fatura::withoutGlobalScopes()->where('empresa_id', $this->empresaB->id)->count());
+    }
+
+    public function test_recibo_de_outra_empresa_nao_e_visivel_nem_se_regista_sobre_fatura_alheia(): void
+    {
+        $faturas = app(FaturaService::class);
+        $fatura = $faturas->emitir($this->rascunhoDaEmpresaA());
+
+        $this->actingAs($this->adminA)
+            ->post(route('faturacao.recibos.guardar'), ['fatura_id' => $fatura->id, 'valor' => '100.00', 'meio_pagamento' => 'transferencia'])
+            ->assertSessionHasNoErrors();
+
+        $recibo = Recibo::withoutGlobalScopes()->firstOrFail();
+
+        $this->actingAs($this->adminB)->get(route('faturacao.recibos.mostrar', $recibo->id))->assertNotFound();
+
+        $this->actingAs($this->adminB)
+            ->post(route('faturacao.recibos.guardar'), ['fatura_id' => $fatura->id, 'valor' => '100.00', 'meio_pagamento' => 'transferencia'])
+            ->assertSessionHasErrors(['fatura_id']);
+
+        $this->assertSame(1, Recibo::withoutGlobalScopes()->count());
+    }
+
+    public function test_ficheiro_saft_de_outra_empresa_nao_se_descarrega(): void
+    {
+        Storage::fake('local');
+        $nome = 'SAFT_2026-01-01_2026-01-31.xml';
+        Storage::disk('local')->put("faturacao/saft/{$this->empresaA->id}/{$nome}", '<AuditFile/>');
+
+        $this->actingAs($this->adminB)->get(route('faturacao.saft.descarregar', $nome))->assertNotFound();
+        $this->actingAs($this->adminA)->get(route('faturacao.saft.descarregar', $nome))->assertOk();
+    }
+
+    public function test_papeis_restritos_nao_entram_na_faturacao_mas_os_restantes_sim(): void
+    {
+        $costureira = $this->criarUtilizador($this->empresaA, 'costureira@a.ao', 'Costureira');
+        $engenheiro = $this->criarUtilizador($this->empresaA, 'engenheiro@a.ao', 'Engenheiro');
+        $operador = $this->criarUtilizador($this->empresaA, 'operador@a.ao', null);
+
+        $this->actingAs($costureira)->get(route('faturacao.clientes.index'))->assertForbidden();
+        $this->actingAs($costureira)->get(route('faturacao.faturas.index'))->assertForbidden();
+        $this->actingAs($engenheiro)->get(route('faturacao.recibos.index'))->assertForbidden();
+
+        $this->actingAs($operador)->get(route('faturacao.clientes.index'))->assertOk();
+        $this->actingAs($this->adminA)->get(route('faturacao.clientes.index'))->assertOk();
+    }
+
+    public function test_administrador_com_papel_restrito_mantem_o_acesso(): void
+    {
+        $misto = $this->criarUtilizador($this->empresaA, 'misto@a.ao', 'Administrador');
+        $registrar = app(PermissionRegistrar::class);
+        $registrar->setPermissionsTeamId($this->empresaA->id);
+        $misto->assignRole(Role::firstOrCreate(['name' => 'Costureira', 'guard_name' => 'web', 'empresa_id' => $this->empresaA->id]));
+        $registrar->setPermissionsTeamId(null);
+        $registrar->forgetCachedPermissions();
+
+        $this->actingAs($misto)->get(route('faturacao.clientes.index'))->assertOk();
+    }
+}
PATCH_EOF

git apply --check /tmp/fase8.patch
git apply /tmp/fase8.patch

php artisan test Modules/Faturacao/tests/Feature/IsolamentoEPermissoesFaturacaoTest.php Modules/Core/tests/Feature/ContextoPermissoesQueueTest.php Modules/Faturacao/tests/Feature/RecibosInterfaceTest.php Modules/Faturacao/tests/Feature/NotasInterfaceTest.php

git add -A
git commit -m "fix(seguranca): papéis restritos fora da Faturação, equipa Spatie restaurada e comandos RGPD com filtro por empresa (fase 8 da PR #33)"
echo "Fase 8 aplicada. Corre a suite completa antes do push."
