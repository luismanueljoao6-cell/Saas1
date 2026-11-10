#!/usr/bin/env bash
# PR #33 — Fase 9: migrations, triggers e integridade na base de dados
# Correr na raiz do repositório, na branch feat/faturacao-fiscal-core.
set -euo pipefail

[ "$(git rev-parse --abbrev-ref HEAD)" = "feat/faturacao-fiscal-core" ] || { echo "Muda para feat/faturacao-fiscal-core"; exit 1; }
git pull --ff-only
[ -z "$(git status --porcelain)" ] || { echo "Working tree com alterações; faz commit/stash primeiro."; exit 1; }

cat > /tmp/fase9.patch <<'PATCH_EOF'
diff --git a/Modules/Faturacao/database/migrations/2026_11_10_000025_integridade_abatimentos_e_fks_fiscais.php b/Modules/Faturacao/database/migrations/2026_11_10_000025_integridade_abatimentos_e_fks_fiscais.php
new file mode 100644
index 0000000..c2ecb39
--- /dev/null
+++ b/Modules/Faturacao/database/migrations/2026_11_10_000025_integridade_abatimentos_e_fks_fiscais.php
@@ -0,0 +1,107 @@
+<?php
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * Fecha na própria base de dados o que o serviço já valida em PHP:
+     *
+     * 1. Um abatimento só pode ligar duas faturas DIFERENTES da empresa indicada
+     *    (uma operação direta na BD não associa documentos de empresas distintas).
+     * 2. Paridade SQLite/MySQL na imutabilidade dos abatimentos: o 000024 só criava
+     *    os triggers em MySQL, por isso a suite SQLite não provava a regra.
+     * 3. As submissões AGT deixam de ser eliminadas em cascata com a empresa
+     *    (são evidência fiscal, tal como faturas, séries, notas e recibos, que o
+     *    000010 já tinha passado a RESTRICT).
+     *
+     * Política de encerramento: uma empresa com documentos fiscais nunca se elimina
+     * definitivamente. Encerra-se (Empresa usa SoftDeletes) e os registos ficam
+     * retidos; o prazo de retenção legal tem de ser confirmado antes de qualquer
+     * rotina de purga. As FK RESTRICT garantem que um forceDelete falha de forma
+     * limpa, sem tocar nos triggers de imutabilidade.
+     */
+    public function up(): void
+    {
+        $driver = DB::connection()->getDriverName();
+
+        if ($driver === 'mysql') {
+            $this->triggersMysql();
+            $this->agtSubmissoesParaRestrict();
+        } elseif ($driver === 'sqlite') {
+            $this->triggersSqlite();
+        }
+    }
+
+    public function down(): void
+    {
+        DB::unprepared('DROP TRIGGER IF EXISTS adiantamento_abatimentos_mesma_empresa');
+
+        if (DB::connection()->getDriverName() === 'sqlite') {
+            DB::unprepared('DROP TRIGGER IF EXISTS adiantamento_abatimentos_sem_update');
+            DB::unprepared('DROP TRIGGER IF EXISTS adiantamento_abatimentos_sem_delete');
+        }
+        // A FK RESTRICT das submissões não se reverte de propósito (voltar a CASCADE reabriria o buraco).
+    }
+
+    protected function triggersMysql(): void
+    {
+        DB::unprepared("
+            CREATE TRIGGER adiantamento_abatimentos_mesma_empresa BEFORE INSERT ON adiantamento_abatimentos FOR EACH ROW
+            BEGIN
+                IF NEW.fatura_adiantamento_id = NEW.fatura_final_id THEN
+                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O adiantamento e a fatura final tem de ser documentos diferentes.';
+                END IF;
+                IF NOT EXISTS (SELECT 1 FROM faturas WHERE id = NEW.fatura_adiantamento_id AND empresa_id = NEW.empresa_id)
+                   OR NOT EXISTS (SELECT 1 FROM faturas WHERE id = NEW.fatura_final_id AND empresa_id = NEW.empresa_id) THEN
+                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O adiantamento e a fatura final tem de pertencer a empresa indicada.';
+                END IF;
+            END
+        ");
+    }
+
+    protected function triggersSqlite(): void
+    {
+        DB::unprepared("
+            CREATE TRIGGER adiantamento_abatimentos_mesma_empresa BEFORE INSERT ON adiantamento_abatimentos
+            BEGIN
+                SELECT RAISE(ABORT, 'O adiantamento e a fatura final tem de ser documentos diferentes.')
+                WHERE NEW.fatura_adiantamento_id = NEW.fatura_final_id;
+                SELECT RAISE(ABORT, 'O adiantamento e a fatura final tem de pertencer a empresa indicada.')
+                WHERE NOT EXISTS (SELECT 1 FROM faturas WHERE id = NEW.fatura_adiantamento_id AND empresa_id = NEW.empresa_id)
+                   OR NOT EXISTS (SELECT 1 FROM faturas WHERE id = NEW.fatura_final_id AND empresa_id = NEW.empresa_id);
+            END
+        ");
+        DB::unprepared("
+            CREATE TRIGGER adiantamento_abatimentos_sem_update BEFORE UPDATE ON adiantamento_abatimentos
+            BEGIN
+                SELECT RAISE(ABORT, 'Os abatimentos de adiantamentos são imutáveis.');
+            END
+        ");
+        DB::unprepared("
+            CREATE TRIGGER adiantamento_abatimentos_sem_delete BEFORE DELETE ON adiantamento_abatimentos
+            BEGIN
+                SELECT RAISE(ABORT, 'Os abatimentos de adiantamentos são imutáveis.');
+            END
+        ");
+    }
+
+    protected function agtSubmissoesParaRestrict(): void
+    {
+        if (! Schema::hasTable('agt_submissoes')) {
+            return;
+        }
+
+        foreach (Schema::getForeignKeys('agt_submissoes') as $fk) {
+            if ($fk['columns'] === ['empresa_id'] && strtolower((string) $fk['on_delete']) === 'cascade') {
+                Schema::table('agt_submissoes', function (Blueprint $t) use ($fk) {
+                    $t->dropForeign($fk['name']);
+                    $t->foreign('empresa_id')->references('id')->on('empresas')->restrictOnDelete();
+                });
+            }
+        }
+    }
+};
diff --git a/Modules/Faturacao/tests/Feature/IntegridadeMigracoesTest.php b/Modules/Faturacao/tests/Feature/IntegridadeMigracoesTest.php
new file mode 100644
index 0000000..da3f5e7
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/IntegridadeMigracoesTest.php
@@ -0,0 +1,166 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Database\QueryException;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Support\Servico;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Services\FaturaService;
+use Tests\TestCase;
+
+/**
+ * Integridade ao nível da base de dados dos abatimentos de adiantamentos:
+ * mesma empresa, imutabilidade e FK RESTRICT. Corre em SQLite e em MySQL (CI).
+ */
+class IntegridadeMigracoesTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresaA;
+
+    protected Empresa $empresaB;
+
+    /** @var array<int, Fatura> */
+    protected array $faturasA = [];
+
+    protected Fatura $faturaB;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
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
+
+        $clienteA = Cliente::create(['empresa_id' => $this->empresaA->id, 'nome' => 'Cliente A', 'nif' => null]);
+        $clienteB = Cliente::create(['empresa_id' => $this->empresaB->id, 'nome' => 'Cliente B', 'nif' => null]);
+
+        $this->faturasA = [$this->rascunho($this->empresaA, $clienteA), $this->rascunho($this->empresaA, $clienteA)];
+        $this->faturaB = $this->rascunho($this->empresaB, $clienteB);
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
+    protected function rascunho(Empresa $empresa, Cliente $cliente): Fatura
+    {
+        return app(FaturaService::class)->criarRascunho($empresa, $cliente, [
+            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
+        ]);
+    }
+
+    protected function inserir(int $empresaId, int $adiantamentoId, int $finalId, string $chave): void
+    {
+        DB::table('adiantamento_abatimentos')->insert([
+            'empresa_id' => $empresaId,
+            'fatura_adiantamento_id' => $adiantamentoId,
+            'fatura_final_id' => $finalId,
+            'valor_centimos' => 1000,
+            'chave' => $chave,
+            'created_at' => now(),
+            'updated_at' => now(),
+        ]);
+    }
+
+    protected function assertRejeitado(callable $operacao, string $fragmento): void
+    {
+        try {
+            $operacao();
+        } catch (QueryException $e) {
+            $this->assertStringContainsString($fragmento, $e->getMessage());
+
+            return;
+        }
+
+        $this->fail('A base de dados devia ter recusado a operação.');
+    }
+
+    public function test_abatimento_entre_faturas_da_mesma_empresa_e_aceite(): void
+    {
+        $this->inserir($this->empresaA->id, $this->faturasA[0]->id, $this->faturasA[1]->id, 'chave-ok');
+
+        $this->assertSame(1, DB::table('adiantamento_abatimentos')->count());
+    }
+
+    public function test_abatimento_nao_liga_faturas_de_outra_empresa(): void
+    {
+        // Fatura final de outra empresa.
+        $this->assertRejeitado(
+            fn () => $this->inserir($this->empresaA->id, $this->faturasA[0]->id, $this->faturaB->id, 'chave-cruzada-1'),
+            'pertencer a empresa'
+        );
+
+        // Empresa indicada diferente da dona das duas faturas.
+        $this->assertRejeitado(
+            fn () => $this->inserir($this->empresaB->id, $this->faturasA[0]->id, $this->faturasA[1]->id, 'chave-cruzada-2'),
+            'pertencer a empresa'
+        );
+
+        $this->assertSame(0, DB::table('adiantamento_abatimentos')->count());
+    }
+
+    public function test_abatimento_nao_liga_uma_fatura_a_si_propria(): void
+    {
+        $this->assertRejeitado(
+            fn () => $this->inserir($this->empresaA->id, $this->faturasA[0]->id, $this->faturasA[0]->id, 'chave-propria'),
+            'documentos diferentes'
+        );
+    }
+
+    public function test_abatimentos_sao_imutaveis_em_qualquer_driver(): void
+    {
+        $this->inserir($this->empresaA->id, $this->faturasA[0]->id, $this->faturasA[1]->id, 'chave-imutavel');
+
+        $this->assertRejeitado(
+            fn () => DB::table('adiantamento_abatimentos')->update(['valor_centimos' => 1]),
+            'imutáveis'
+        );
+        $this->assertRejeitado(
+            fn () => DB::table('adiantamento_abatimentos')->delete(),
+            'imutáveis'
+        );
+
+        $this->assertSame(1000, (int) DB::table('adiantamento_abatimentos')->value('valor_centimos'));
+    }
+
+    public function test_fk_da_empresa_nos_abatimentos_e_restrict(): void
+    {
+        $fks = collect(Schema::getForeignKeys('adiantamento_abatimentos'))
+            ->first(fn (array $fk) => $fk['columns'] === ['empresa_id']);
+
+        $this->assertNotNull($fks);
+        $this->assertSame('restrict', strtolower((string) $fks['on_delete']));
+    }
+
+    public function test_tabelas_fiscais_nao_se_apagam_em_cascata_com_a_empresa_em_mysql(): void
+    {
+        if (DB::getDriverName() !== 'mysql') {
+            $this->markTestSkipped('A troca CASCADE → RESTRICT só se aplica em MySQL (produção).');
+        }
+
+        foreach (['faturas', 'series', 'notas_credito_debito', 'recibos', 'agt_submissoes'] as $tabela) {
+            $fk = collect(Schema::getForeignKeys($tabela))->first(fn (array $f) => $f['columns'] === ['empresa_id']);
+
+            $this->assertNotNull($fk, $tabela);
+            $this->assertSame('restrict', strtolower((string) $fk['on_delete']), $tabela);
+        }
+    }
+}
diff --git a/scripts/verificar-migracoes.sh b/scripts/verificar-migracoes.sh
new file mode 100755
index 0000000..bdd56e7
--- /dev/null
+++ b/scripts/verificar-migracoes.sh
@@ -0,0 +1,70 @@
+#!/usr/bin/env bash
+# Verifica as migrations da branch contra um MySQL REAL (não SQLite):
+#   1. instalação limpa            4. atualização a partir da main
+#   2. triggers e FKs esperados    5. (opcional) atualização com dump de dados reais
+#   3. rollback das migrations novas e nova aplicação
+#
+# Usa a base configurada no .env — USA UMA BASE DESCARTÁVEL: faz migrate:fresh!
+# Variáveis: BASE_REF (default origin/main), DUMP_SQL (dump de uma instalação na main).
+set -euo pipefail
+
+BASE_REF="${BASE_REF:-origin/main}"
+RAIZ="$(git rev-parse --show-toplevel)"
+cd "$RAIZ"
+
+[ "$(php artisan tinker --execute='echo DB::getDriverName();' 2>/dev/null | tail -1)" = "mysql" ] \
+  || { echo "O .env tem de apontar para MySQL (esta verificação não faz sentido em SQLite)."; exit 1; }
+
+consulta() { php artisan tinker --execute="echo json_encode(DB::select(\"$1\"));" 2>/dev/null | tail -1; }
+
+verificar_triggers_e_fks() {
+  local esperados=(adiantamento_abatimentos_sem_update adiantamento_abatimentos_sem_delete adiantamento_abatimentos_mesma_empresa \
+    faturas_impedir_alteracao faturas_impedir_exclusao recibos_impedir_alteracao series_impedir_retrocesso)
+  local triggers; triggers="$(consulta 'SHOW TRIGGERS')"
+  for t in "${esperados[@]}"; do
+    [[ "$triggers" == *"$t"* ]] || { echo "FALTA o trigger $t"; exit 1; }
+  done
+  local fks; fks="$(consulta "SELECT TABLE_NAME t, DELETE_RULE r FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'empresas' AND TABLE_NAME IN ('faturas','series','notas_credito_debito','recibos','agt_submissoes','adiantamento_abatimentos') AND DELETE_RULE <> 'RESTRICT'")"
+  [ "$fks" = "[]" ] || { echo "FK da empresa ainda em CASCADE/SET NULL: $fks"; exit 1; }
+  echo "   triggers e FKs OK"
+}
+
+echo "1/5 Instalação limpa"
+php artisan migrate:fresh --force
+verificar_triggers_e_fks
+
+echo "2/5 Estado final sem migrations pendentes"
+php artisan migrate:status | grep -q "Pending" && { echo "Há migrations pendentes."; exit 1; } || echo "   OK"
+
+echo "3/5 Rollback das migrations novas e nova aplicação"
+git fetch -q origin "${BASE_REF#origin/}" || true
+NOVAS=$(git diff --name-only --diff-filter=A "$BASE_REF"...HEAD -- '*/database/migrations/*.php' | wc -l)
+echo "   $NOVAS migrations novas face a $BASE_REF"
+[ "$NOVAS" -gt 0 ] && php artisan migrate:rollback --step="$NOVAS" --force
+php artisan migrate --force
+verificar_triggers_e_fks
+
+echo "4/5 Atualização a partir da $BASE_REF (schema da main -> schema da branch)"
+TMP="$(mktemp -d)"; trap 'git worktree remove --force "$TMP" 2>/dev/null || true' EXIT
+git worktree add -q --detach "$TMP" "$BASE_REF"
+ln -s "$RAIZ/vendor" "$TMP/vendor"; cp "$RAIZ/.env" "$TMP/.env"
+( cd "$TMP" && php artisan migrate:fresh --force )
+php artisan migrate --force
+verificar_triggers_e_fks
+
+if [ -n "${DUMP_SQL:-}" ]; then
+  echo "5/5 Atualização com dados reais de teste ($DUMP_SQL)"
+  php artisan db:wipe --force
+  DB_NAME="$(php artisan tinker --execute='echo DB::getDatabaseName();' 2>/dev/null | tail -1)"
+  mysql -h "${DB_HOST:-127.0.0.1}" -u "${DB_USERNAME:-root}" -p"${DB_PASSWORD:-}" "$DB_NAME" < "$DUMP_SQL"
+  ANTES="$(consulta 'SELECT (SELECT COUNT(*) FROM faturas) f, (SELECT COUNT(*) FROM recibos) r, (SELECT COUNT(*) FROM fatura_linhas) l')"
+  php artisan migrate --force
+  DEPOIS="$(consulta 'SELECT (SELECT COUNT(*) FROM faturas) f, (SELECT COUNT(*) FROM recibos) r, (SELECT COUNT(*) FROM fatura_linhas) l')"
+  [ "$ANTES" = "$DEPOIS" ] || { echo "Contagens mudaram: $ANTES -> $DEPOIS"; exit 1; }
+  verificar_triggers_e_fks
+  echo "   documentos antigos intactos: $DEPOIS"
+else
+  echo "5/5 Ignorado (define DUMP_SQL com um dump da main para testar com dados reais)"
+fi
+
+echo "Migrations verificadas."
PATCH_EOF

git apply --check /tmp/fase9.patch
git apply /tmp/fase9.patch

php artisan test Modules/Faturacao/tests/Feature/IntegridadeMigracoesTest.php Modules/Faturacao/tests/Feature/AdiantamentoNotaCreditoTest.php Modules/Faturacao/tests/Feature/CadeiaFiscalTest.php

git add -A
git commit -m "fix(faturacao): integridade de abatimentos na BD, paridade de triggers e FK RESTRICT nas submissões AGT (fase 9 da PR #33)"
echo "Fase 9 aplicada. Agora corre, numa base MySQL DESCARTÁVEL: ./scripts/verificar-migracoes.sh"
