#!/usr/bin/env bash
# =============================================================================
# aplicar-fase1-bloco3.sh — SaaS1 · Fase 1 · Bloco 3 (carga mista de concorrência real)
#   Acrescenta 3 testes de carga mista (grupo concorrencia-real, MySQL): recibos, notas de crédito e
#   abatimentos em simultâneo sobre os MESMOS documentos. Não encontrou deadlocks nem falhas técnicas;
#   com REPEATABLE READ falham (uma das corridas deixou uma fatura com saldo de -12600.00).
#   Não altera código de produção. Actualiza os documentos de auditoria (P-11).
#
# Requer os blocos 1 e 2 já aplicados (commit 4269d47 ou posterior). Gerado contra 4269d477daa0a383d5cb9a0d0b563b9b73f3f44d.
# Sem rede, sem commit, sem push.
#
#   bash aplicar-fase1-bloco3.sh --ver   mostra os ficheiros e confirma que o patch encaixa (não altera nada)
#   bash aplicar-fase1-bloco3.sh         aplica o patch (fica em stage)
# =============================================================================
set -euo pipefail

BASE="4269d477daa0a383d5cb9a0d0b563b9b73f3f44d"
RAMO="plan/fase-1-integridade-critica-20261116"
MODO="${1:-aplicar}"

cd "$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "Corre dentro do repositório Saas1."; exit 1; }
[ -f artisan ] || { echo "artisan não encontrado: não é a raiz do Saas1."; exit 1; }

ATUAL="$(git rev-parse --abbrev-ref HEAD)"
case "$ATUAL" in
  main|master) echo "ERRO: estás na '$ATUAL'. Muda para a branch de trabalho: git switch $RAMO"; exit 2;;
esac
[ "$ATUAL" = "$RAMO" ] || echo "AVISO: branch '$ATUAL' (esperada: $RAMO)"

[ -f Modules/Faturacao/tests/Feature/LimiteMensalConcorrenciaRealTest.php ] || {
  echo "O bloco 2 não está aplicado (falta LimiteMensalConcorrenciaRealTest.php). Aplica primeiro os blocos 1 e 2 e faz git pull."; exit 5; }

if ! git merge-base --is-ancestor "$BASE" HEAD 2>/dev/null; then
  echo "AVISO: o commit base ${BASE:0:7} não está no histórico desta branch. Faz git pull e tenta de novo."
fi

patch_stdin() { sed -n "/^: <<'__PATCH__'\$/,/^__PATCH__\$/p" "$0" | sed '1d;$d'; }

if git apply --check --reverse <(patch_stdin) >/dev/null 2>&1; then
  echo "O patch já está aplicado nesta árvore. Nada a fazer."; exit 0
fi

# O patch tem de entrar isolado: nada em stage. Alterações não relacionadas por commitar e ficheiros
# não versionados (como este script) não impedem a aplicação nem entram no commit do patch.
if ! git diff --cached --quiet; then
  echo "Há alterações em stage:"; git diff --cached --name-status | head -15
  echo "Faz commit ou 'git restore --staged .' antes de aplicar (o patch deve entrar isolado)."; exit 3
fi

# --index recusa se algum ficheiro que o patch toca tiver alterações por commitar.
if ! patch_stdin | git apply --check --index -; then
  echo "O patch NÃO encaixa nesta branch: ou mudou desde o commit ${BASE:0:7}, ou algum ficheiro que o patch toca"
  echo "tem alterações por commitar (ver 'does not match index' acima). Nada foi alterado."
  echo "Cola-me o resultado de: git log --oneline -5 && git status --short   para eu ajustar."; exit 4
fi

OUTRAS="$(git status --porcelain --untracked-files=no | wc -l | tr -d ' ')"
if [ "$OUTRAS" -gt 0 ]; then
  echo "Nota: $OUTRAS ficheiro(s) com alterações por commitar que NÃO fazem parte deste patch ficam como estão"
  echo "      e não entram no commit do patch (usa 'git commit' sem -a)."
fi

echo "O patch altera:"
patch_stdin | git apply --stat -
if [ "$MODO" = "--ver" ]; then echo; echo "(--ver: nada foi aplicado)"; exit 0; fi

patch_stdin | git apply --index
echo
echo "Aplicado e em stage. Próximos passos:"
echo "  1) export XDEBUG_MODE=off"
echo "  2) git diff --cached --name-only --diff-filter=AM | grep '\\.php\$' | xargs vendor/bin/pint --test"
echo "  3) git commit -m 'test(concorrencia): carga mista de recibos, notas de crédito e abatimentos com processos reais'   (sem -a e sem git add -A: este script não faz parte do patch)"
echo "  4) git push   e vê os jobs 'qualidade' e 'testes-mysql' na PR #58 (o grupo concorrencia-real passa a ter 19 testes)"
exit 0
: <<'__PATCH__'
diff --git a/Modules/Faturacao/tests/Feature/CargaMistaConcorrenciaRealTest.php b/Modules/Faturacao/tests/Feature/CargaMistaConcorrenciaRealTest.php
new file mode 100644
index 0000000000000000000000000000000000000000..c3f74ebf10004a62cfd74efa5befc9d4cf5b731c
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/CargaMistaConcorrenciaRealTest.php
@@ -0,0 +1,247 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Support\Facades\DB;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Services\FaturaService;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Modules\Faturacao\Services\ReciboService;
+use PHPUnit\Framework\Attributes\Group;
+use Tests\Support\PreparaEmpresaConcorrente;
+use Tests\Support\ProcessosConcorrentes;
+use Tests\TestCase;
+
+/**
+ * Carga mista sobre os MESMOS documentos (MySQL, processos reais): recibos, notas de crédito e
+ * abatimentos de adiantamentos a competir ao mesmo tempo. Cada tipo de operação já tem o seu teste;
+ * aqui procura-se o que só aparece quando se cruzam: deadlocks entre serviços que bloqueiam por
+ * ordens diferentes e limites que cada serviço respeita sozinho mas que juntos deixam ultrapassar.
+ *
+ * Que invariantes valem (e quais NÃO valem):
+ *  - recibos + abatimentos nunca excedem o total da fatura (cada um respeita o saldo no seu momento);
+ *  - as notas de crédito somam, no máximo, o total da fatura (mais 1 cêntimo por linha de arredondamento);
+ *  - uma nota de crédito emitida DEPOIS de recebimentos pode deixar o saldo da fatura negativo: é crédito
+ *    a favor do cliente, não um erro, por isso o saldo final só tem de ser >= 0 sem notas de crédito;
+ *  - nunca se abate mais do que o adiantamento recebeu, nem se abate e credita a mesma parte do adiantamento;
+ *  - as numerações ficam contínuas e toda a recusa é regra de negócio (nunca deadlock, timeout de lock
+ *    ou violação de integridade).
+ */
+#[Group('concorrencia-real')]
+class CargaMistaConcorrenciaRealTest extends TestCase
+{
+    use PreparaEmpresaConcorrente;
+    use ProcessosConcorrentes;
+
+    protected function setUp(): void
+    {
+        $this->exigirOptInConcorrenciaReal();
+        parent::setUp();
+        $this->exigirMysqlDeTestes();
+
+        $this->prepararEmpresaFiscal('carga-mista');
+    }
+
+    /** @return array<int, array<string, mixed>> */
+    protected function linha(string $preco, string $descricao = 'Serviço'): array
+    {
+        return [['descricao' => $descricao, 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => 14]];
+    }
+
+    protected function centimos(mixed $valor): int
+    {
+        return (int) round(((float) $valor) * 100);
+    }
+
+    protected function faturaFinal(string $preco): Fatura
+    {
+        $faturas = app(FaturaService::class);
+
+        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linha($preco, 'Final')));
+    }
+
+    /** Adiantamento (FA) de 5000.00 + 14% = 5700.00, já recebido por inteiro. */
+    protected function adiantamentoRecebido(): Fatura
+    {
+        $faturas = app(FaturaService::class);
+        $adiantamento = $faturas->emitir($faturas->criarRascunhoAdiantamento($this->empresa, $this->cliente, $this->linha('5000.00', 'Sinal')));
+        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '5700.00', 'dinheiro', $adiantamento->id);
+
+        return $adiantamento;
+    }
+
+    /** @return array<string, mixed> */
+    protected function reciboParaFatura(Fatura $fatura, string $valor): array
+    {
+        return ['acao' => 'emitir_recibo', 'args' => [
+            'empresa_id' => $this->empresa->id, 'cliente_id' => $this->cliente->id,
+            'valor' => $valor, 'meio' => 'dinheiro', 'fatura_id' => $fatura->id,
+        ]];
+    }
+
+    /** @return array<string, mixed> */
+    protected function abatimento(Fatura $adiantamento, Fatura $final, string $valor): array
+    {
+        return ['acao' => 'abater', 'args' => [
+            'empresa_id' => $this->empresa->id, 'fa_id' => $adiantamento->id, 'ft_id' => $final->id,
+            'valor' => $valor, 'chave' => 'carga-mista-abat-'.uniqid(),
+        ]];
+    }
+
+    /** @return array<string, mixed> uma nota de crédito (rascunho criado aqui, emitido no worker) */
+    protected function notaDeCredito(Fatura $fatura, string $preco): array
+    {
+        $rascunho = app(NotaCreditoDebitoService::class)->criarRascunho(
+            $this->empresa->id, $this->cliente->id, 'credito', 'Correção '.uniqid(), $this->linha($preco, 'Correção'), $fatura->id,
+        );
+
+        return ['acao' => 'emitir_nota', 'args' => ['nota_id' => $rascunho->id]];
+    }
+
+    /** @param  array<int, array<string, mixed>>  $lote */
+    protected function correrMisto(array $lote): array
+    {
+        mt_srand(20261122);
+        shuffle($lote);
+
+        $resultados = $this->correrEmParalelo('misto', $lote, $this->ambienteFiscal());
+
+        foreach ($resultados as $r) {
+            if ($r['ok']) {
+                continue;
+            }
+
+            $this->assertMatchesRegularExpression(
+                '/^(DomainException|InvalidArgumentException|Modules\\\\Faturacao\\\\Exceptions\\\\[A-Za-z]+Exception): /',
+                $r['erro'],
+                'Falha técnica em vez de recusa de negócio: '.$r['erro'],
+            );
+        }
+
+        $recusas = count(array_filter($resultados, fn ($r) => ! $r['ok']));
+        $this->assertGreaterThan(0, $recusas, 'Sem recusas não houve disputa pelo saldo.');
+        $this->assertGreaterThan(0, count($resultados) - $recusas);
+
+        return $resultados;
+    }
+
+    protected function recebido(Fatura $fatura): int
+    {
+        return $this->centimos(DB::table('recibos')->where('fatura_id', $fatura->id)->where('estado', 'emitido')->sum('valor'));
+    }
+
+    protected function creditado(Fatura $fatura): int
+    {
+        return $this->centimos(DB::table('notas_credito_debito')->where('fatura_id', $fatura->id)->where('tipo', 'credito')->where('estado', 'emitida')->sum('valor_total'));
+    }
+
+    protected function abatidoNaFinal(Fatura $final): int
+    {
+        return (int) DB::table('adiantamento_abatimentos')->where('fatura_final_id', $final->id)->sum('valor_centimos');
+    }
+
+    protected function abatidoDoAdiantamento(Fatura $adiantamento): int
+    {
+        return (int) DB::table('adiantamento_abatimentos')->where('fatura_adiantamento_id', $adiantamento->id)->sum('valor_centimos');
+    }
+
+    protected function linhasDeNotas(): int
+    {
+        return (int) DB::table('notas_credito_debito_linhas')
+            ->whereIn('nota_credito_debito_id', DB::table('notas_credito_debito')->where('empresa_id', $this->empresa->id)->where('estado', 'emitida')->pluck('id')->all())
+            ->count();
+    }
+
+    protected function assertNumeracoesContinuas(): void
+    {
+        foreach (['recibos', 'notas_credito_debito'] as $tabela) {
+            $numeros = DB::table($tabela)->where('empresa_id', $this->empresa->id)->whereNotNull('numero_sequencial')
+                ->orderBy('serie_id')->orderBy('numero_sequencial')->get(['serie_id', 'numero_sequencial'])->groupBy('serie_id');
+
+            foreach ($numeros as $serieId => $linhas) {
+                $sequencia = $linhas->pluck('numero_sequencial')->map(fn ($n) => (int) $n)->all();
+                $this->assertSame(range(1, count($sequencia)), $sequencia, "Lacuna na numeração de {$tabela} (série {$serieId}).");
+            }
+        }
+    }
+
+    public function test_recibos_notas_de_credito_e_abatimentos_sobre_a_mesma_fatura_respeitam_os_limites(): void
+    {
+        $final = $this->faturaFinal('10000.00'); // 11400.00
+        $adiantamento = $this->adiantamentoRecebido(); // 5700.00 por abater
+
+        $lote = [];
+        for ($i = 0; $i < 8; $i++) {
+            $lote[] = $this->reciboParaFatura($final, '1500.00');
+        }
+        for ($i = 0; $i < 6; $i++) {
+            $lote[] = $this->notaDeCredito($final, '1000.00');
+        }
+        for ($i = 0; $i < 6; $i++) {
+            $lote[] = $this->abatimento($adiantamento, $final, '1000.00');
+        }
+
+        $this->correrMisto($lote);
+
+        $total = $this->centimos(Fatura::withoutGlobalScopes()->findOrFail($final->id)->valor_total);
+        $recebido = $this->recebido($final);
+        $abatido = $this->abatidoNaFinal($final);
+
+        $this->assertSame(1140000, $total);
+        $this->assertLessThanOrEqual($total, $recebido + $abatido, sprintf('Cobrado a mais: recebido %d + abatido %d > total %d.', $recebido, $abatido, $total));
+        $this->assertLessThanOrEqual($total + $this->linhasDeNotas(), $this->creditado($final), 'As notas de crédito excedem a fatura.');
+        $this->assertLessThanOrEqual(570000, $abatido, 'Nunca se abate mais do que o adiantamento recebeu (5700.00).');
+        $this->assertNumeracoesContinuas();
+    }
+
+    public function test_recibos_e_abatimentos_sem_notas_de_credito_nunca_deixam_o_saldo_negativo(): void
+    {
+        $final = $this->faturaFinal('10000.00'); // 11400.00
+        $adiantamento = $this->adiantamentoRecebido();
+
+        $lote = [];
+        for ($i = 0; $i < 12; $i++) {
+            $lote[] = $this->reciboParaFatura($final, '1500.00');
+        }
+        for ($i = 0; $i < 8; $i++) {
+            $lote[] = $this->abatimento($adiantamento, $final, '1000.00');
+        }
+
+        $this->correrMisto($lote);
+
+        $total = $this->centimos(Fatura::withoutGlobalScopes()->findOrFail($final->id)->valor_total);
+        $saldo = $total - $this->recebido($final) - $this->abatidoNaFinal($final);
+
+        $this->assertGreaterThanOrEqual(0, $saldo, "Saldo negativo sem notas de crédito: {$saldo} cêntimos.");
+        $this->assertLessThanOrEqual(570000, $this->abatidoNaFinal($final));
+        $this->assertNumeracoesContinuas();
+    }
+
+    public function test_abatimentos_e_notas_de_credito_sobre_o_mesmo_adiantamento_nao_se_sobrepoem(): void
+    {
+        $final = $this->faturaFinal('30000.00'); // 34200.00: capacidade de sobra para os abatimentos
+        $adiantamento = $this->adiantamentoRecebido(); // 5700.00
+
+        $lote = [];
+        for ($i = 0; $i < 6; $i++) {
+            $lote[] = $this->abatimento($adiantamento, $final, '1000.00');
+        }
+        for ($i = 0; $i < 6; $i++) {
+            $lote[] = $this->notaDeCredito($adiantamento, '1000.00'); // 1140.00 cada
+        }
+
+        $this->correrMisto($lote);
+
+        $total = $this->centimos(Fatura::withoutGlobalScopes()->findOrFail($adiantamento->id)->valor_total);
+        $abatido = $this->abatidoDoAdiantamento($adiantamento);
+        $creditado = $this->creditado($adiantamento);
+
+        $this->assertSame(570000, $total);
+        $this->assertLessThanOrEqual(
+            $total + $this->linhasDeNotas(),
+            $abatido + $creditado,
+            sprintf('A mesma parte do adiantamento foi abatida e creditada: abatido %d + creditado %d > %d.', $abatido, $creditado, $total),
+        );
+        $this->assertNumeracoesContinuas();
+    }
+}
diff --git a/docs/auditorias/fase-1/concorrencia-e-alocacoes.md b/docs/auditorias/fase-1/concorrencia-e-alocacoes.md
index bddc539851770dd1f57a540181bb6ed378b8dff8..4a51f19d7dde8750e6ab60e3601265914fc377d6 100644
--- a/docs/auditorias/fase-1/concorrencia-e-alocacoes.md
+++ b/docs/auditorias/fase-1/concorrencia-e-alocacoes.md
@@ -54,6 +54,16 @@ ## 2. Defeito confirmado: sobre-alocação sob concorrência real
 leitura normal. Passam com os dois níveis de isolamento e ficam com teste de regressão com processos reais
 (`FaturacaoEstudioConcorrenciaRealTest`, `PedidoFaturacaoConcorrenciaRealTest`, `PagamentoConcorrenciaRealTest`).
 
+**Carga mista (bloco 3).** `CargaMistaConcorrenciaRealTest` cruza, em 12 a 20 processos sobre os mesmos documentos,
+recibos, notas de crédito e abatimentos, que bloqueiam por ordens diferentes (recibo: fatura; nota: nota e fatura;
+abatimento: as duas faturas por id). Não há deadlocks nem falhas técnicas: todas as recusas são regras de negócio.
+Os invariantes que valem são: recibos + abatimentos nunca excedem a fatura; as notas de crédito somam no máximo a
+fatura (mais 1 cêntimo por linha de arredondamento); nunca se abate e credita a mesma parte do adiantamento; as
+numerações ficam contínuas. Uma nota de crédito emitida depois de recebimentos pode deixar o saldo negativo: é
+crédito a favor do cliente e não um erro, por isso o saldo só tem de ser >= 0 quando não há notas de crédito.
+A primeira versão do teste afirmava saldo >= 0 em todos os casos e falhou por esse motivo; o invariante estava errado,
+não o código.
+
 **Limite.** Sem teste de concorrência real: o check-out de sessões do Estúdio e a criação/reativação de utilizadores
 com limite do plano (o lock está no controller, exige pedidos HTTP em paralelo) e `CompraService::registarPagamento`
 (por revisão de código bloqueia a compra antes de ler, mas não há teste).
@@ -98,9 +108,9 @@ ## 5. Evidência depois das alterações
 |---|---|
 | `phpunit` (SQLite, tudo) | 1057 testes, 2991 asserções, 10 ignorados (5 anteriores + 5 de concorrência real que exigem MySQL), 1 incompleto — OK |
 | `phpunit --exclude-group=auditoria` (MySQL 8.0.46) | 1047 testes, 2970 asserções, 10 ignorados, 1 incompleto — OK |
-| `migrate:fresh` + `phpunit --group=concorrencia-real` (MySQL, `SAAS1_CONCORRENCIA_REAL=1`) | 16 testes, 317 asserções — OK |
-| O mesmo grupo com `DB_ISOLATION_LEVEL='REPEATABLE READ'` | 4 falham: recibos 6 em vez de 1, abatimentos 4 em vez de 2, NC 6 em vez de 1, limite mensal 6 em vez de 2 |
-| O mesmo grupo em SQLite | 16 ignorados, sem erros |
+| `migrate:fresh` + `phpunit --group=concorrencia-real` (MySQL, `SAAS1_CONCORRENCIA_REAL=1`) | 19 testes, 417 asserções — OK |
+| O mesmo grupo com `DB_ISOLATION_LEVEL='REPEATABLE READ'` | 7 falham: recibos 6 em vez de 1, abatimentos 4 em vez de 2, NC 6 em vez de 1, limite mensal 6 em vez de 2 e os 3 cenários de carga mista (um deles com saldo de -12600.00 sem notas de crédito) |
+| O mesmo grupo em SQLite | 19 ignorados, sem erros |
 | `AuditoriaSubscricoesTest` completo (MySQL) | 10 testes, 30 asserções — OK |
 | `migrate:fresh` → `migrate:rollback --step=1` → `migrate` com a BD vazia | MySQL 8.0.46 e SQLite OK (tabela, 3 triggers e índices repostos) |
 | `migrate:rollback --step=1` com alocações gravadas (MySQL) | recusado com "Rollback recusado"; as 4 alocações ficam |
diff --git a/docs/auditorias/fase-1/pendencias-e-decisoes.md b/docs/auditorias/fase-1/pendencias-e-decisoes.md
index d48d97919c8baf6f99828ce98fb3ee4ad7b8bfca..f1b9e57aef8f76074e8d2576e92ba0b6af41bed7 100644
--- a/docs/auditorias/fase-1/pendencias-e-decisoes.md
+++ b/docs/auditorias/fase-1/pendencias-e-decisoes.md
@@ -14,6 +14,6 @@ # Pendências e decisões — Fase 1
 | P-08 | Estúdio: sinal de pacote ainda emitido como FT (FA exige P-02) | Secções 9, 19 e 20 da decisão | Depende de P-02 | Bloqueada |
 | P-09 | Pint só corre nos ficheiros alterados | `ci.yml` | Decidir se a dívida de formatação histórica se paga numa PR dedicada | Aberta |
 | P-10 | Contagem de testes executados na CI não registada | `referencias-oficiais.md`, secção 5 | Correr `bash fase1-bloco1-saft-xsd.sh --suite` e registar o resumo | Aberta |
-| P-11 | Concorrência real (MySQL, vários processos): cobertos recibos, abatimentos, NC, alocações de recibo, limite mensal de faturas, sinal e saldo final do Estúdio, sinal/fatura final/pagamento final do Ateliê e confirmação de pagamentos de subscrições; faltam o check-out de sessões do Estúdio e a criação/reativação de utilizadores com limite do plano (o lock está no controller, exige pedidos HTTP em paralelo) e `CompraService::registarPagamento` (por revisão de código bloqueia a compra antes de ler, sem teste) | `ConcorrenciaFinanceiraRealTest`, `ReciboAlocacaoConcorrenciaRealTest`, `LimiteMensalConcorrenciaRealTest`, `FaturacaoEstudioConcorrenciaRealTest`, `PedidoFaturacaoConcorrenciaRealTest` e `PagamentoConcorrenciaRealTest` (grupo `concorrencia-real`); `AbatimentoIdempotenciaConcorrenteTest` continua a só simular a corrida | Implementar os restantes sobre o modelo de `EmissaoConcorrenciaRealTest`; só conta como prova em MySQL | Parcial — pouco por cobrir |
+| P-11 | Concorrência real (MySQL, vários processos): cobertos recibos, abatimentos, NC (também cruzados entre si), alocações de recibo, limite mensal de faturas, sinal e saldo final do Estúdio, sinal/fatura final/pagamento final do Ateliê e confirmação de pagamentos de subscrições; faltam o check-out de sessões do Estúdio e a criação/reativação de utilizadores com limite do plano (o lock está no controller, exige pedidos HTTP em paralelo) e `CompraService::registarPagamento` (por revisão de código bloqueia a compra antes de ler, sem teste) | `ConcorrenciaFinanceiraRealTest`, `ReciboAlocacaoConcorrenciaRealTest`, `LimiteMensalConcorrenciaRealTest`, `FaturacaoEstudioConcorrenciaRealTest`, `PedidoFaturacaoConcorrenciaRealTest`, `PagamentoConcorrenciaRealTest` e `CargaMistaConcorrenciaRealTest` (grupo `concorrencia-real`); `AbatimentoIdempotenciaConcorrenteTest` continua a só simular a corrida | Implementar os restantes sobre o modelo de `EmissaoConcorrenciaRealTest`; só conta como prova em MySQL | Parcial — pouco por cobrir |
 | P-12 | O isolamento da ligação MySQL passou a READ COMMITTED (`config/database.php`, variável `DB_ISOLATION_LEVEL`) para corrigir recibos, abatimentos e NC simultâneos que excediam o limite do documento | `concorrencia-e-alocacoes.md`, secção 2 | Confirmar a decisão global (alternativa: leituras bloqueantes em cada serviço) e a compatibilidade com produção e réplicas (com binlog em STATEMENT o READ COMMITTED não é permitido; o padrão do MySQL 8 é ROW) | Aberta |
 | P-13 | Alocações por linha ainda não ligadas ao `ReciboService`, ao `AgtDocumentoBuilder` nem ao `FaturacaoGateway`; por confirmar a leitura de `lineNo`, as alocações a linhas de NC/ND e o disponível por linha depois de uma NC | `concorrencia-e-alocacoes.md`, secção 3 | Depende de P-02 e P-03 | Aberta |
diff --git a/docs/auditorias/fase-1/relatorio-geral.md b/docs/auditorias/fase-1/relatorio-geral.md
index f854993ffbb5516e8aace46c9c4c5e981ba65ef4..f62d41271956f585d51e10a197a9a844322b4027 100644
--- a/docs/auditorias/fase-1/relatorio-geral.md
+++ b/docs/auditorias/fase-1/relatorio-geral.md
@@ -20,7 +20,7 @@ ## Matriz dos critérios de aceitação
 | 8 | SAF-T validado contra a especificação | Não comprovada | Exportador provisório; divergências em `saft-ao.md`; XSD real nunca usado em teste |
 | 9 | Isolamento multiempresa comprovado | Parcial | Testes na PR #58; faltam testes IDOR HTTP por módulo e de jobs/commands |
 | 10 | Migrations seguras | Parcial | As da PR #58 interrompem com dados inválidos; rollback e dados históricos não auditados |
-| 11 | Concorrência testada em MySQL | Parcial | O grupo `concorrencia-real` (executado na CI com `SAAS1_CONCORRENCIA_REAL=1`) tinha 3 testes (8 emissões simultâneas sem lacunas e com cadeia de hash contínua, 6 emissões simultâneas da mesma fatura e 8 reenvios simultâneos de campanha) e passa a ter 16: acrescenta recibos, abatimentos e NC simultâneos (`ConcorrenciaFinanceiraRealTest`), alocações de recibo (`ReciboAlocacaoConcorrenciaRealTest`), o limite mensal de faturas do plano (`LimiteMensalConcorrenciaRealTest`), sinal e saldo final de projectos do Estúdio (`FaturacaoEstudioConcorrenciaRealTest`), sinal, fatura final e pagamento final de pedidos do Ateliê (`PedidoFaturacaoConcorrenciaRealTest`) e a confirmação de pagamentos de subscrições (`PagamentoConcorrenciaRealTest`). Com REPEATABLE READ falham 4 (6 recibos de 1140.00 aceites numa fatura de 1140.00, abatimentos, NC e 6 faturas emitidas com limite mensal de 2); corrigido com `isolation_level` READ COMMITTED (P-12). Sem teste com processos reais para o check-out de sessões do Estúdio, a criação de utilizadores com limite do plano e os pagamentos de compras (P-11) |
+| 11 | Concorrência testada em MySQL | Parcial | O grupo `concorrencia-real` (executado na CI com `SAAS1_CONCORRENCIA_REAL=1`) tinha 3 testes (8 emissões simultâneas sem lacunas e com cadeia de hash contínua, 6 emissões simultâneas da mesma fatura e 8 reenvios simultâneos de campanha) e passa a ter 19: acrescenta recibos, abatimentos e NC simultâneos (`ConcorrenciaFinanceiraRealTest`), alocações de recibo (`ReciboAlocacaoConcorrenciaRealTest`), o limite mensal de faturas do plano (`LimiteMensalConcorrenciaRealTest`), sinal e saldo final de projectos do Estúdio (`FaturacaoEstudioConcorrenciaRealTest`), sinal, fatura final e pagamento final de pedidos do Ateliê (`PedidoFaturacaoConcorrenciaRealTest`) a confirmação de pagamentos de subscrições (`PagamentoConcorrenciaRealTest`) e 3 cenários de carga mista com recibos, NC e abatimentos em simultâneo sobre os mesmos documentos (`CargaMistaConcorrenciaRealTest`: sem deadlocks nem saldos negativos sem NC, e sem sobreposição entre abatimentos e NC do mesmo adiantamento). Com REPEATABLE READ falham 7 (6 recibos de 1140.00 aceites numa fatura de 1140.00, abatimentos, NC, 6 faturas emitidas com limite mensal de 2 e a carga mista, que chegou a deixar a fatura com saldo de -12600.00); corrigido com `isolation_level` READ COMMITTED (P-12). Sem teste com processos reais para o check-out de sessões do Estúdio, a criação de utilizadores com limite do plano e os pagamentos de compras (P-11) |
 | 12 | Ateliê fiscalmente integrado | Bloqueada | P-07 |
 | 13 | Estúdio fiscalmente integrado | Parcial | Saldos lidos do gateway; sinal ainda como FT (P-08) |
 | 14 | Subscrições consistentes | Parcial | 10 testes de auditoria na CI; limite mensal de faturas e confirmação de pagamentos validados com processos reais em MySQL (`concorrencia-e-alocacoes.md`); P-06 por decidir |
diff --git a/tests/Support/concorrencia_worker.php b/tests/Support/concorrencia_worker.php
index 0b684208ff2c5e0236bd2e77b7858194297dfb4a..49e033891eca053c16e0da03109d80dd1687fd9f 100644
--- a/tests/Support/concorrencia_worker.php
+++ b/tests/Support/concorrencia_worker.php
@@ -39,6 +39,12 @@
 $barreira = (float) ($argv[2] ?? 0);
 $args = json_decode((string) ($argv[3] ?? '{}'), true) ?: [];
 
+// Carga mista: um mesmo lote de processos executa acções diferentes (['acao' => ..., 'args' => [...]]).
+if ($acao === 'misto') {
+    $acao = (string) ($args['acao'] ?? '');
+    $args = (array) ($args['args'] ?? []);
+}
+
 $app = require __DIR__.'/../../bootstrap/app.php';
 $app->make(Kernel::class)->bootstrap();
 
__PATCH__
