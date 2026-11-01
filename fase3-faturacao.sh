#!/usr/bin/env bash
# =============================================================================
# fase3-faturacao.sh — interface fiscal do módulo Faturação (fase 3 da auditoria)
#
# Feito para o teu `main` a partir do commit 7cc3e46 (o da fase 2b). Acrescenta, com testes HTTP:
#   - estado do documento na AGT à vista (faturas, notas, recibos e listas): "Enviado" não é
#     confirmação fiscal, só "Válido" é; erros da AGT visíveis
#   - botão "Criar rascunho de correção" numa fatura inválida/rejeitada pela AGT (Administrador)
#   - notas de crédito e de débito: lista, rascunho, emissão (Administrador) e eliminação de rascunhos;
#     a nota de crédito parte das linhas da fatura; a de débito não leva IVA
#   - recibos: lista e emissão a partir da fatura (Administrador), com o valor em dívida
#   - na fatura: recebido / creditado / em dívida e as ações Registar recibo, Nota de crédito, Nota de débito
#   - o formulário de nova fatura conserva as linhas depois de um erro; menu lateral com Notas e Recibos
#
# Fluxo: pré-verificações -> mede os testes ANTES -> aplica -> sintaxe -> Pint
#        -> testes (módulo + suite completa; falhas que já existiam não contam) -> commit -> push.
#        Se algo falhar antes do commit, o que o script tocou é revertido.
#        O próprio ficheiro do script pode estar na raiz do repositório: é ignorado e nunca entra no commit.
#
# Uso:   bash fase3-faturacao.sh [--sem-push] [--sem-commit] [--so-faturacao] [--manter-em-falha]
#   --sem-push         faz o commit mas não envia para o GitHub
#   --sem-commit       aplica e testa, deixa as alterações por commitar (implica --sem-push)
#   --so-faturacao     só corre os testes do módulo Faturação (mais rápido)
#   --manter-em-falha  em caso de falha, não reverte (para inspecionares)
# =============================================================================
set -Eeuo pipefail

BASE_TESTADA="7cc3e46"
PATCH_SHA256="fa0c4553e8b72ea0a28f7cff6561beba8fd952f3c42ca4ad429e02284bcf8fbe"

SEM_PUSH=0; SEM_COMMIT=0; SO_FATURACAO=0; MANTER=0
APLICADO=0; COMMITADO=0
TMP=""; SELF_REL=""
TOCADOS=()

c_azul=$'\033[1;34m'; c_verde=$'\033[1;32m'; c_amarelo=$'\033[1;33m'; c_vermelho=$'\033[1;31m'; c_fim=$'\033[0m'
passo()  { printf '\n%s==> %s%s\n' "$c_azul" "$*" "$c_fim"; }
ok()     { printf '%s✓ %s%s\n' "$c_verde" "$*" "$c_fim"; }
aviso()  { printf '%s! %s%s\n' "$c_amarelo" "$*" "$c_fim" >&2; }
erro()   { printf '%s✗ %s%s\n' "$c_vermelho" "$*" "$c_fim" >&2; }

ajuda() { sed -n '2,/^# =====*$/p' "$0" | sed 's/^# \{0,1\}//' | sed '$d'; }

for arg in "$@"; do
  case "$arg" in
    --sem-push) SEM_PUSH=1 ;;
    --sem-commit) SEM_COMMIT=1; SEM_PUSH=1 ;;
    --so-faturacao) SO_FATURACAO=1 ;;
    --manter-em-falha) MANTER=1 ;;
    -h|--ajuda|--help) ajuda; exit 0 ;;
    *) erro "Opção desconhecida: $arg"; ajuda; exit 2 ;;
  esac
done

# Reverte SÓ o que o script tocou (nunca "git checkout ." — o próprio script pode estar na raiz do repo).
reverter() {
  if [ "$APLICADO" = 1 ] && [ "$COMMITADO" = 0 ]; then
    APLICADO=0
    if [ "$MANTER" = 1 ]; then
      aviso "Alterações mantidas (--manter-em-falha). Ficheiros tocados: ${#TOCADOS[@]} (git status)."
      return 0
    fi
    local f
    for f in "${TOCADOS[@]}"; do
      git reset -q -- "$f" 2>/dev/null || true
      if git ls-files --error-unmatch -- "$f" >/dev/null 2>&1; then
        git checkout -q -- "$f" 2>/dev/null || true
      else
        rm -f -- "$f"
      fi
    done
    find Modules/Faturacao -type d -empty -delete 2>/dev/null || true
    aviso "Alterações revertidas: o repositório ficou como estava."
  fi
}

limpeza() {
  local codigo=$?
  trap - EXIT ERR
  if [ "$codigo" -ne 0 ]; then reverter; fi
  if [ -n "$TMP" ]; then rm -rf "$TMP"; fi
  exit "$codigo"
}
trap limpeza EXIT

falhou() {
  local codigo=$? linha="${1:-?}"
  trap - ERR
  erro "Falhou (linha ${linha}, código ${codigo})."
  exit "$codigo"
}
trap 'falhou $LINENO' ERR
trap 'trap - ERR; erro "Interrompido."; exit 130' INT TERM

hash_de() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'; else shasum -a 256 "$1" | awk '{print $1}'; fi
}

# Testes que falharam num ficheiro JUnit (uma linha "Classe::teste" por falha).
falhas_de() {
  python3 - "$1" <<'PY'
import sys, xml.etree.ElementTree as ET
try:
    raiz = ET.parse(sys.argv[1]).getroot()
except Exception:
    sys.exit(3)
for t in raiz.iter('testcase'):
    if t.find('failure') is not None or t.find('error') is not None:
        print(f"{t.get('class') or t.get('classname')}::{t.get('name')}")
PY
}

# Alterações por commitar, ignorando o próprio ficheiro do script.
estado_sujo() {
  git status --porcelain --untracked-files=all | while IFS= read -r linha; do
    [ -n "$SELF_REL" ] && [ "${linha:3}" = "$SELF_REL" ] && continue
    printf '%s\n' "$linha"
  done
}

# ---------------------------------------------------------------- pré-verificações
passo "Pré-verificações"

[ -f "$0" ] || { erro "Corre este ficheiro com: bash fase3-faturacao.sh (não por pipe)."; exit 1; }
SELF="$(cd "$(dirname "$0")" && pwd -P)/$(basename "$0")"
RAIZ="$(git rev-parse --show-toplevel 2>/dev/null)" || { erro "Corre isto dentro do repositório Saas1."; exit 1; }
cd "$RAIZ"
RAIZ="$(pwd -P)"
SELF_REL="$(python3 -c 'import os,sys; r=os.path.relpath(sys.argv[1], sys.argv[2]); print("" if r.startswith("..") else r)' "$SELF" "$RAIZ")"
[ -d Modules/Faturacao ] || { erro "Não encontro Modules/Faturacao: estás no repositório certo?"; exit 1; }

for cmd in git php python3 awk sed; do
  command -v "$cmd" >/dev/null 2>&1 || { erro "Falta o comando '$cmd'."; exit 1; }
done

if [ -f Modules/Faturacao/app/Http/Controllers/NotaController.php ]; then
  ok "Esta fase já está aplicada neste repositório. Nada a fazer."
  exit 0
fi

SUJO="$(estado_sujo)"
if [ -n "$SUJO" ]; then
  erro "Tens alterações por commitar. Faz commit (ou git stash) e volta a correr."
  printf '%s\n' "$SUJO" >&2
  exit 1
fi

[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || git checkout -q main
git pull --ff-only origin main
ok "main atualizado: $(git log --oneline -1)"

[ -f Modules/Faturacao/app/Services/AgtCorrecaoService.php ] || {
  erro "Falta a fase 2b (AgtCorrecaoService). Corre primeiro o fase2b-faturacao.sh."
  exit 1
}

TMP="$(mktemp -d)"
LOG_MODULO="${TMPDIR:-/tmp}/fase3-testes-faturacao.log"
LOG_TODOS="${TMPDIR:-/tmp}/fase3-testes-completos.log"

sed '1,/^__PATCH_FASE3__$/d' "$0" > "$TMP/fase3.patch"
[ -s "$TMP/fase3.patch" ] || { erro "O patch não está no ficheiro (descarrega o script de novo)."; exit 1; }
[ "$(hash_de "$TMP/fase3.patch")" = "$PATCH_SHA256" ] || { erro "O SHA-256 do patch não confere: o ficheiro foi truncado ou alterado. Descarrega-o de novo."; exit 1; }

git apply --numstat "$TMP/fase3.patch" | awk '{print $3}' > "$TMP/ficheiros.txt"

if ! git apply --check --whitespace=nowarn "$TMP/fase3.patch" 2>"$TMP/check.err"; then
  erro "O patch não encaixa no teu main:"
  cat "$TMP/check.err" >&2
  if git cat-file -e "${BASE_TESTADA}^{commit}" 2>/dev/null; then
    MUDADOS="$(git diff --name-only "$BASE_TESTADA" HEAD -- $(cat "$TMP/ficheiros.txt") 2>/dev/null || true)"
    [ -n "$MUDADOS" ] && { aviso "Ficheiros que mudaram no main desde ${BASE_TESTADA} e o patch também toca:"; printf '    %s\n' $MUDADOS >&2; }
  fi
  erro "Nada foi alterado. Manda-me este erro e eu volto a montar o patch sobre o teu main."
  exit 1
fi
ok "O patch encaixa; nada foi alterado ainda."

# Ficheiros que o script pode tocar (para reverter com precisão).
mapfile -t TOCADOS < "$TMP/ficheiros.txt"

export XDEBUG_MODE=off

# ---------------------------------------------------------------- estado dos testes antes
BASE_CONHECIDA=0
: > "$TMP/antes.falhas"
if [ "$SO_FATURACAO" = 0 ]; then
  passo "A medir os testes ANTES do patch (as falhas que já existem não contam)"
  php -d memory_limit=-1 artisan test --log-junit="$TMP/antes.xml" >"$TMP/antes.log" 2>&1 || true
  if [ -s "$TMP/antes.xml" ] && falhas_de "$TMP/antes.xml" | sort -u > "$TMP/antes.falhas"; then
    BASE_CONHECIDA=1
    ok "Falhas já existentes no main: $(wc -l < "$TMP/antes.falhas" | tr -d ' ')"
  else
    : > "$TMP/antes.falhas"
    aviso "Não consegui medir o estado inicial: exijo que a suite fique toda verde."
  fi
fi

# ---------------------------------------------------------------- aplicar
passo "A aplicar as correções"
APLICADO=1
git apply --whitespace=nowarn "$TMP/fase3.patch"
ok "$(wc -l < "$TMP/ficheiros.txt" | tr -d ' ') ficheiros alterados/criados"

passo "Sintaxe PHP"
git ls-files -m -o --exclude-standard -- '*.php' | while read -r f; do php -l "$f" >/dev/null; done
ok "Sem erros de sintaxe"

if [ -x vendor/bin/pint ]; then
  passo "Pint (só nos ficheiros alterados)"
  php -d memory_limit=-1 vendor/bin/pint --dirty >/dev/null
  ok "Código formatado"
fi

# ---------------------------------------------------------------- testes
passo "Testes do módulo Faturação (têm de passar todos)"
if ! php -d memory_limit=-1 artisan test Modules/Faturacao >"$LOG_MODULO" 2>&1; then
  erro "Falhou algum teste do módulo Faturação. Resumo:"
  sed 's/\x1b\[[0-9;]*m//g' "$LOG_MODULO" | grep -E "FAIL|FAILED|⨯|Failed asserting|Tests:" | head -20 >&2 || true
  erro "Log completo: $LOG_MODULO"
  exit 1
fi
tail -n 6 "$LOG_MODULO"
ok "Módulo Faturação: verde"

if [ "$SO_FATURACAO" = 0 ]; then
  passo "Suite completa (só conta o que passou a falhar por causa desta fase)"
  php -d memory_limit=-1 artisan test --log-junit="$TMP/depois.xml" >"$LOG_TODOS" 2>&1 || true
  [ -s "$TMP/depois.xml" ] || { erro "A suite completa não produziu resultados. Log: $LOG_TODOS"; tail -n 25 "$LOG_TODOS" >&2; exit 1; }
  falhas_de "$TMP/depois.xml" | sort -u > "$TMP/depois.falhas"

  if [ "$BASE_CONHECIDA" = 1 ]; then
    NOVAS="$(comm -13 "$TMP/antes.falhas" "$TMP/depois.falhas")"
  else
    NOVAS="$(cat "$TMP/depois.falhas")"
  fi

  if [ -n "$NOVAS" ]; then
    erro "Estes testes falham agora e não falhavam antes:"
    printf '%s\n' "$NOVAS" >&2
    erro "Log completo: $LOG_TODOS"
    exit 1
  fi

  if [ -s "$TMP/antes.falhas" ]; then
    aviso "Estes testes JÁ falhavam no main antes desta fase (não têm a ver com ela):"
    sed 's/^/    /' "$TMP/antes.falhas" >&2
  fi
  ok "Suite completa: nada passou a falhar"
fi

# ---------------------------------------------------------------- commit e push
if [ "$SEM_COMMIT" = 1 ]; then
  passo "Pronto (sem commit)"
  ok "Alterações aplicadas e testadas, por commitar. Revê com: git status"
  exit 0
fi

passo "Commit"
if [ -n "$SELF_REL" ]; then git add -A -- . ":(exclude)${SELF_REL}"; else git add -A; fi
git commit -q \
  -m "feat(faturacao): interface fiscal (estado AGT, correção de rejeitadas, notas de crédito/débito e recibos)" \
  -m "Estado do documento na AGT nas faturas, notas, recibos e listas (só 'Válido' é confirmação fiscal); botão de correção de fatura inválida/rejeitada; notas de crédito e de débito (lista, rascunho, emissão, eliminação de rascunho); recibos com valor em dívida; recebido/creditado/em dívida na fatura; menu lateral; o formulário de fatura conserva as linhas depois de um erro; testes HTTP."
COMMITADO=1
ok "$(git log --oneline -1)"

if [ "$SEM_PUSH" = 1 ]; then
  aviso "Sem push (--sem-push). Quando quiseres: git push origin main"
else
  passo "Push para o main"
  git push origin main || {
    aviso "O commit está feito localmente, mas o push falhou. Tenta: git pull --rebase origin main && git push origin main"
    exit 1
  }
  ok "Enviado para o GitHub"
fi

cat <<'FIM'

─────────────────────────────────────────────────────────────────────────────
Feito. Vê no browser: abre uma fatura emitida (Faturas) — aparecem «Recebimentos»,
«Registar recibo», «Nota de crédito (parcial)» e «Nota de débito»; no menu lateral há
«Notas de crédito e débito» e «Recibos». Com FATURACAO_AGT_ATIVO=true aparece também o
«Estado na AGT» e, numa fatura inválida/rejeitada, o botão «Criar rascunho de correção».

Continua em aberto: versão para impressão com o layout fiscal (por validar na lei), tabela
de motivos de isenção (Anexo 6.4), XSD oficial do SAF-T e homologação real com a AGT.
─────────────────────────────────────────────────────────────────────────────
FIM
exit 0
__PATCH_FASE3__
diff --git a/Modules/Core/app/Support/Navegacao.php b/Modules/Core/app/Support/Navegacao.php
index 6355c85..314dcf5 100644
--- a/Modules/Core/app/Support/Navegacao.php
+++ b/Modules/Core/app/Support/Navegacao.php
@@ -47,6 +47,8 @@ public static function grupos(): array
                 'id' => 'faturacao', 'rotulo' => 'Faturação e SAF-T', 'servico' => 'faturacao',
                 'itens' => [
                     ['rota' => 'faturacao.faturas.index', 'rotulo' => 'Faturas', 'icone' => 'fatura', 'ativo' => 'faturacao.faturas.*'],
+                    ['rota' => 'faturacao.notas.index', 'rotulo' => 'Notas de crédito e débito', 'icone' => 'fatura', 'ativo' => 'faturacao.notas.*'],
+                    ['rota' => 'faturacao.recibos.index', 'rotulo' => 'Recibos', 'icone' => 'recibo', 'ativo' => 'faturacao.recibos.*'],
                     ['rota' => 'faturacao.clientes.index', 'rotulo' => 'Clientes', 'icone' => 'cliente', 'ativo' => 'faturacao.clientes.*'],
                     ['rota' => 'faturacao.produtos.index', 'rotulo' => 'Produtos', 'icone' => 'pacote', 'ativo' => 'faturacao.produtos.*'],
                     ['rota' => 'faturacao.saft.criar', 'rotulo' => 'Exportação SAF-T', 'icone' => 'exportar', 'ativo' => 'faturacao.saft.*', 'admin' => true],
diff --git a/Modules/Faturacao/README.md b/Modules/Faturacao/README.md
index a566f89..adab7d8 100644
--- a/Modules/Faturacao/README.md
+++ b/Modules/Faturacao/README.md
@@ -349,6 +349,27 @@ ## Fase 2 da auditoria (complemento)
 
 **Continua em aberto** (não se resolve só com código): conformidade fiscal integral e certificação do
 software; XSD oficial e estrutura SAF-T completa; confirmação da tabela de motivos de isenção (Anexo 6.4);
-homologação real com a AGT; descontos, divisas, retenções e os restantes tipos de documento; interface
-para notas de crédito parciais, notas de débito e recibos.
+homologação real com a AGT; descontos, divisas, retenções e os restantes tipos de documento; versão
+para impressão com o layout fiscal.
+
+## Fase 3 da auditoria (interface)
+
+A interface passa a mostrar o que o serviço já fazia. Cada ponto tem teste HTTP.
+
+- **Estado na AGT à vista** (com a comunicação AGT ativa) nas faturas, notas e recibos e nas listas:
+  «Enviado» não é confirmação fiscal — só «Válido» o é; erros da AGT visíveis.
+- **Correção de fatura inválida/rejeitada** pelo botão «Criar rascunho de correção» (Administrador), com a
+  mesma lógica do comando `faturacao:agt-corrigir`: a fatura rejeitada e a correção apontam uma para a outra.
+- **Notas de crédito e de débito**: lista, rascunho, emissão (Administrador) e eliminação de rascunhos. A
+  nota de crédito parte das linhas da fatura (reduz quantidades para creditar só uma parte); a de débito
+  não leva IVA. Erros de regra voltam ao formulário com a mensagem, sem perder as linhas.
+- **Recibos**: lista e emissão a partir da fatura (Administrador), com o valor em dívida
+  (total − recibos − notas de crédito) e recusa de valores acima disso.
+- **Na fatura**: «Recebimentos» (recebido, creditado, em dívida) e as ações «Registar recibo», «Nota de
+  crédito (parcial)» e «Nota de débito».
+- O formulário de nova fatura conserva as linhas depois de um erro.
+
+Fica por fazer: versão para impressão (o layout fiscal tem de ser validado contra a lei), recibos de
+adiantamento (a AGT ainda não os suporta aqui), edição de notas em rascunho (elimina-se e recria-se) e
+correção assistida de notas de crédito/débito e recibos rejeitados.
 
diff --git a/Modules/Faturacao/app/Http/Controllers/FaturaController.php b/Modules/Faturacao/app/Http/Controllers/FaturaController.php
index 53912a3..b216046 100644
--- a/Modules/Faturacao/app/Http/Controllers/FaturaController.php
+++ b/Modules/Faturacao/app/Http/Controllers/FaturaController.php
@@ -14,8 +14,10 @@
 use Modules\Faturacao\Models\Cliente;
 use Modules\Faturacao\Models\Fatura;
 use Modules\Faturacao\Models\Produto;
+use Modules\Faturacao\Services\AgtCorrecaoService;
 use Modules\Faturacao\Services\FaturaService;
 use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Modules\Faturacao\Services\ReciboService;
 use Throwable;
 
 /**
@@ -27,12 +29,14 @@ class FaturaController extends Controller
     public function __construct(
         protected FaturaService $faturaService,
         protected NotaCreditoDebitoService $notaService,
+        protected AgtCorrecaoService $correcoes,
+        protected ReciboService $recibos,
     ) {}
 
     public function index(): View
     {
         return view('faturacao::faturas.index', [
-            'faturas' => Fatura::with('cliente')->latest('id')->paginate(20),
+            'faturas' => Fatura::with('cliente', 'agtSubmissao')->latest('id')->paginate(20),
         ]);
     }
 
@@ -43,6 +47,15 @@ public function criar(): View
             'produtos' => Produto::ativos()->orderBy('nome')->get(),
             'taxaIvaGeral' => (float) config('faturacao.taxa_iva_geral'),
             ...$this->dadosDeIsencao(),
+            // Depois de um erro, o formulário volta com as linhas que o utilizador tinha escrito.
+            'linhasIniciais' => collect((array) old('linhas', []))->map(fn ($linha) => [
+                'produto_id' => $linha['produto_id'] ?? null,
+                'descricao' => (string) ($linha['descricao'] ?? ''),
+                'quantidade' => $linha['quantidade'] ?? 1,
+                'preco_unitario' => $linha['preco_unitario'] ?? 0,
+                'taxa_iva' => $linha['taxa_iva'] ?? config('faturacao.taxa_iva_geral'),
+                'motivo_isencao' => $linha['motivo_isencao'] ?? '',
+            ])->values()->all(),
         ]);
     }
 
@@ -88,11 +101,37 @@ public function guardar(GuardarFaturaRequest $request): RedirectResponse
 
     public function mostrar(int $fatura): View
     {
+        $modelo = Fatura::with('linhas', 'cliente', 'notasCreditoDebito', 'recibos', 'agtSubmissao', 'correcao')->findOrFail($fatura);
+
         return view('faturacao::faturas.mostrar', [
-            'fatura' => Fatura::with('linhas', 'cliente', 'notasCreditoDebito')->findOrFail($fatura),
+            'fatura' => $modelo,
+            // Só faz sentido (e só se calcula) para faturas emitidas: recebido, creditado e valor em dívida.
+            'resumo' => $modelo->estaEmitido() ? $this->recibos->resumoDaFatura($modelo) : null,
         ]);
     }
 
+    /**
+     * Cria o rascunho da fatura que corrige uma fatura invalidada/rejeitada pela AGT (regra E46:
+     * a correção é um documento novo). Não emite nada: o rascunho segue o fluxo normal.
+     */
+    public function corrigir(int $fatura): RedirectResponse
+    {
+        $modelo = Fatura::with('agtSubmissao')->findOrFail($fatura);
+
+        if (! $modelo->agtSubmissao) {
+            return back()->with('erro', 'Esta fatura não tem registo de envio à AGT: não há nada a corrigir.');
+        }
+
+        try {
+            $rascunho = $this->correcoes->criarRascunhoDeCorrecao($modelo->agtSubmissao);
+        } catch (\DomainException $e) {
+            return back()->with('erro', $e->getMessage());
+        }
+
+        return redirect()->route('faturacao.faturas.editar', $rascunho->id)
+            ->with('sucesso', 'Rascunho de correção criado. Revê os valores e emite-o: segue para a AGT como correção da fatura rejeitada.');
+    }
+
     public function editar(int $fatura): View|RedirectResponse
     {
         $modelo = Fatura::with('linhas')->findOrFail($fatura);
diff --git a/Modules/Faturacao/app/Http/Controllers/NotaController.php b/Modules/Faturacao/app/Http/Controllers/NotaController.php
new file mode 100644
index 0000000..33332fe
--- /dev/null
+++ b/Modules/Faturacao/app/Http/Controllers/NotaController.php
@@ -0,0 +1,169 @@
+<?php
+
+namespace Modules\Faturacao\Http\Controllers;
+
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Routing\Controller;
+use Illuminate\View\View;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
+use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;
+use Modules\Faturacao\Http\Requests\GuardarNotaRequest;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\NotaCreditoDebito;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Throwable;
+
+/**
+ * Notas de crédito e de débito. {nota} chega como int (sem route model binding): o binding
+ * corre antes do middleware 'tenant' e a TenantScope fail-closed devolveria sempre 404.
+ */
+class NotaController extends Controller
+{
+    public function __construct(protected NotaCreditoDebitoService $notas) {}
+
+    public function index(): View
+    {
+        return view('faturacao::notas.index', [
+            'notas' => NotaCreditoDebito::with('cliente', 'agtSubmissao')->latest('id')->paginate(20),
+        ]);
+    }
+
+    public function criar(Request $request): View|RedirectResponse
+    {
+        $tipo = $request->query('tipo') === 'debito' ? 'debito' : 'credito';
+        $fatura = null;
+
+        if ($request->filled('fatura')) {
+            $fatura = Fatura::with('linhas')->find($request->integer('fatura'));
+
+            if (! $fatura || ! $fatura->estaEmitido()) {
+                return redirect()->route('faturacao.faturas.index')->with('erro', 'A fatura de origem tem de existir e estar emitida.');
+            }
+        } elseif ($tipo === 'credito') {
+            return redirect()->route('faturacao.faturas.index')
+                ->with('erro', 'Uma nota de crédito parte de uma fatura emitida: abre a fatura e escolhe a nota de crédito.');
+        }
+
+        // Depois de um erro, o formulário volta com o que o utilizador tinha escrito.
+        $linhasAntigas = collect((array) old('linhas', []))->map(fn ($linha) => [
+            'produto_id' => $linha['produto_id'] ?? null,
+            'descricao' => (string) ($linha['descricao'] ?? ''),
+            'quantidade' => $linha['quantidade'] ?? 1,
+            'preco_unitario' => $linha['preco_unitario'] ?? 0,
+            'taxa_iva' => $linha['taxa_iva'] ?? 0,
+            'motivo_isencao' => $linha['motivo_isencao'] ?? '',
+        ])->values()->all();
+
+        // Nota de crédito: parte das linhas da fatura (reduz as quantidades para creditar só uma parte).
+        $linhasDaFatura = $tipo === 'credito' && $fatura
+            ? $fatura->linhas->map(fn ($linha) => [
+                'produto_id' => $linha->produto_id,
+                'descricao' => $linha->descricao,
+                'quantidade' => (float) $linha->quantidade,
+                'preco_unitario' => (float) $linha->preco_unitario,
+                'taxa_iva' => (float) $linha->taxa_iva,
+                'motivo_isencao' => $linha->motivo_isencao ?? '',
+            ])->values()->all()
+            : [];
+
+        return view('faturacao::notas.criar', [
+            'tipo' => $tipo,
+            'fatura' => $fatura,
+            'clientes' => $fatura ? collect() : Cliente::orderBy('nome')->get(['id', 'nome', 'nif']),
+            'linhasIniciais' => $linhasAntigas !== [] ? $linhasAntigas : $linhasDaFatura,
+            'taxaNaInterface' => $tipo === 'credito' || (bool) config('faturacao.nota_debito_liquida_iva', false),
+        ]);
+    }
+
+    public function guardar(GuardarNotaRequest $request): RedirectResponse
+    {
+        $empresa = $request->user()->empresa;
+        abort_if($empresa === null, 403, 'Utilizador sem empresa associada.');
+
+        $tipo = $request->validated('tipo');
+        $faturaId = $request->validated('fatura_id');
+        $linhas = $request->validated('linhas');
+
+        // Nota de débito sem IVA: a taxa não vem do formulário, é sempre 0.
+        if ($tipo === 'debito' && ! config('faturacao.nota_debito_liquida_iva', false)) {
+            $linhas = array_map(fn (array $linha) => [...$linha, 'taxa_iva' => 0, 'motivo_isencao' => null], $linhas);
+        }
+
+        // O cliente da nota é sempre o da fatura de origem.
+        $clienteId = $faturaId !== null
+            ? (int) Fatura::findOrFail($faturaId)->cliente_id
+            : (int) $request->validated('cliente_id');
+
+        try {
+            $nota = $this->notas->criarRascunho(
+                (int) $empresa->id,
+                $clienteId,
+                $tipo,
+                $request->validated('motivo'),
+                $linhas,
+                $faturaId !== null ? (int) $faturaId : null,
+            );
+        } catch (\DomainException|\InvalidArgumentException $e) {
+            // Regras de negócio: mensagens seguras para o utilizador.
+            return back()->withInput()->withErrors(['nota' => $e->getMessage()]);
+        }
+
+        return redirect()->route('faturacao.notas.mostrar', $nota->id)
+            ->with('sucesso', 'Rascunho criado. Revê os valores antes de emitir.');
+    }
+
+    public function mostrar(int $nota): View
+    {
+        return view('faturacao::notas.mostrar', [
+            'nota' => NotaCreditoDebito::with('linhas', 'cliente', 'fatura', 'agtSubmissao')->findOrFail($nota),
+        ]);
+    }
+
+    public function emitir(int $nota): RedirectResponse
+    {
+        $modelo = NotaCreditoDebito::findOrFail($nota);
+
+        $this->garantirQuePodeEmitir($modelo->empresa);
+
+        try {
+            $this->notas->emitir($modelo);
+        } catch (\DomainException|\InvalidArgumentException|EmissaoNaoPermitidaException $e) {
+            // Mensagens de regra de negócio (ex.: crédito acima do faturado): seguras para o utilizador.
+            return back()->with('erro', $e->getMessage());
+        } catch (AssinaturaFiscalException) {
+            return back()->with('erro', 'Não foi possível assinar o documento. A equipa técnica foi notificada.');
+        } catch (Throwable $e) {
+            report($e);
+
+            return back()->with('erro', 'Ocorreu um erro ao emitir a nota. Tenta novamente.');
+        }
+
+        return redirect()->route('faturacao.notas.mostrar', $modelo->id)
+            ->with('sucesso', 'Nota emitida com sucesso.');
+    }
+
+    public function eliminar(int $nota): RedirectResponse
+    {
+        $modelo = NotaCreditoDebito::findOrFail($nota);
+
+        try {
+            $this->notas->eliminarRascunho($modelo);
+        } catch (\DomainException $e) {
+            return back()->with('erro', $e->getMessage());
+        }
+
+        return redirect()->route('faturacao.notas.index')->with('sucesso', 'Rascunho eliminado.');
+    }
+
+    protected function garantirQuePodeEmitir(Empresa $empresa): void
+    {
+        abort_unless(
+            $empresa->podeEmitirFaturas(),
+            402,
+            'A tua empresa está no período de tolerância — podes consultar documentos antigos, mas não emitir novos até regularizares a subscrição.'
+        );
+    }
+}
diff --git a/Modules/Faturacao/app/Http/Controllers/ReciboController.php b/Modules/Faturacao/app/Http/Controllers/ReciboController.php
new file mode 100644
index 0000000..e0017dd
--- /dev/null
+++ b/Modules/Faturacao/app/Http/Controllers/ReciboController.php
@@ -0,0 +1,100 @@
+<?php
+
+namespace Modules\Faturacao\Http\Controllers;
+
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Routing\Controller;
+use Illuminate\View\View;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
+use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;
+use Modules\Faturacao\Http\Requests\GuardarReciboRequest;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\Recibo;
+use Modules\Faturacao\Services\ReciboService;
+use Modules\Faturacao\Support\Dinheiro;
+use Throwable;
+
+/**
+ * Recibos de faturas emitidas. Um recibo é emitido logo (não tem rascunho), por isso criar e
+ * guardar são ações de Administrador. {recibo} chega como int (ver FaturaController).
+ */
+class ReciboController extends Controller
+{
+    public function __construct(protected ReciboService $recibos) {}
+
+    public function index(): View
+    {
+        return view('faturacao::recibos.index', [
+            'recibos' => Recibo::with('cliente', 'fatura', 'agtSubmissao')->latest('id')->paginate(20),
+        ]);
+    }
+
+    public function criar(Request $request): View|RedirectResponse
+    {
+        $fatura = Fatura::with('cliente')->find($request->integer('fatura'));
+
+        if (! $fatura || ! $fatura->estaEmitido()) {
+            return redirect()->route('faturacao.faturas.index')->with('erro', 'Escolhe uma fatura emitida para registar o recibo.');
+        }
+
+        $resumo = $this->recibos->resumoDaFatura($fatura);
+
+        if ($resumo['saldo'] <= 0) {
+            return redirect()->route('faturacao.faturas.mostrar', $fatura->id)->with('erro', 'Esta fatura não tem valor em dívida.');
+        }
+
+        return view('faturacao::recibos.criar', [
+            'fatura' => $fatura,
+            'resumo' => $resumo,
+            'saldoSugerido' => Dinheiro::formatar($resumo['saldo']),
+            'meiosPagamento' => (array) config('faturacao.meios_pagamento', []),
+        ]);
+    }
+
+    public function guardar(GuardarReciboRequest $request): RedirectResponse
+    {
+        $fatura = Fatura::findOrFail($request->validated('fatura_id'));
+
+        $this->garantirQuePodeEmitir($fatura->empresa);
+
+        try {
+            $recibo = $this->recibos->emitir(
+                (int) $fatura->empresa_id,
+                (int) $fatura->cliente_id,
+                $request->validated('valor'),
+                $request->validated('meio_pagamento'),
+                (int) $fatura->id,
+            );
+        } catch (\DomainException|\InvalidArgumentException|EmissaoNaoPermitidaException $e) {
+            // Ex.: valor acima do que está em dívida (já deduzidas as notas de crédito).
+            return back()->withInput()->withErrors(['recibo' => $e->getMessage()]);
+        } catch (AssinaturaFiscalException) {
+            return back()->withInput()->withErrors(['recibo' => 'Não foi possível assinar o documento. A equipa técnica foi notificada.']);
+        } catch (Throwable $e) {
+            report($e);
+
+            return back()->withInput()->withErrors(['recibo' => 'Ocorreu um erro ao emitir o recibo. Tenta novamente.']);
+        }
+
+        return redirect()->route('faturacao.recibos.mostrar', $recibo->id)
+            ->with('sucesso', "Recibo {$recibo->numero_documento} emitido.");
+    }
+
+    public function mostrar(int $recibo): View
+    {
+        return view('faturacao::recibos.mostrar', [
+            'recibo' => Recibo::with('cliente', 'fatura', 'agtSubmissao')->findOrFail($recibo),
+        ]);
+    }
+
+    protected function garantirQuePodeEmitir(Empresa $empresa): void
+    {
+        abort_unless(
+            $empresa->podeEmitirFaturas(),
+            402,
+            'A tua empresa está no período de tolerância — podes consultar documentos antigos, mas não emitir novos até regularizares a subscrição.'
+        );
+    }
+}
diff --git a/Modules/Faturacao/app/Http/Requests/GuardarNotaRequest.php b/Modules/Faturacao/app/Http/Requests/GuardarNotaRequest.php
new file mode 100644
index 0000000..d4eece6
--- /dev/null
+++ b/Modules/Faturacao/app/Http/Requests/GuardarNotaRequest.php
@@ -0,0 +1,40 @@
+<?php
+
+namespace Modules\Faturacao\Http\Requests;
+
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
+
+/** Rascunho de nota de crédito (sempre sobre uma fatura emitida) ou de débito (com ou sem fatura). */
+class GuardarNotaRequest extends FormRequest
+{
+    public function authorize(): bool
+    {
+        return $this->user() !== null && $this->user()->empresa_id !== null;
+    }
+
+    public function rules(): array
+    {
+        $empresaId = $this->user()->empresa_id;
+        $credito = $this->input('tipo') === 'credito';
+        // Nota de débito sem IVA (a regra por omissão): a taxa não vem do formulário, é sempre 0.
+        $taxaObrigatoria = $credito || (bool) config('faturacao.nota_debito_liquida_iva', false);
+
+        return [
+            'tipo' => ['required', Rule::in(['credito', 'debito'])],
+            'fatura_id' => [$credito ? 'required' : 'nullable', 'integer', Rule::exists('faturas', 'id')->where('empresa_id', $empresaId)],
+            // Sem fatura de origem (só nota de débito) o cliente tem de ser indicado.
+            'cliente_id' => ['required_without:fatura_id', 'nullable', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
+            // A AGT recebe no máximo 60 caracteres do motivo: não se aceita mais, para não truncar em silêncio.
+            'motivo' => ['required', 'string', 'min:3', 'max:60'],
+
+            'linhas' => ['required', 'array', 'min:1', 'max:100'],
+            'linhas.*.produto_id' => ['nullable', 'integer', Rule::exists('produtos', 'id')->where('empresa_id', $empresaId)],
+            'linhas.*.descricao' => ['required', 'string', 'max:255'],
+            'linhas.*.quantidade' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:10000'],
+            'linhas.*.preco_unitario' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
+            'linhas.*.taxa_iva' => [$taxaObrigatoria ? 'required' : 'nullable', 'numeric', 'between:0,100'],
+            'linhas.*.motivo_isencao' => ['nullable', 'string', Rule::in(array_keys((array) config('faturacao.motivos_isencao', [])))],
+        ];
+    }
+}
diff --git a/Modules/Faturacao/app/Http/Requests/GuardarReciboRequest.php b/Modules/Faturacao/app/Http/Requests/GuardarReciboRequest.php
new file mode 100644
index 0000000..4b85245
--- /dev/null
+++ b/Modules/Faturacao/app/Http/Requests/GuardarReciboRequest.php
@@ -0,0 +1,26 @@
+<?php
+
+namespace Modules\Faturacao\Http\Requests;
+
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
+
+/** Recibo de uma fatura emitida (o recibo de adiantamento não tem interface: a AGT ainda não o suporta). */
+class GuardarReciboRequest extends FormRequest
+{
+    public function authorize(): bool
+    {
+        return $this->user() !== null && $this->user()->empresa_id !== null;
+    }
+
+    public function rules(): array
+    {
+        $empresaId = $this->user()->empresa_id;
+
+        return [
+            'fatura_id' => ['required', 'integer', Rule::exists('faturas', 'id')->where('empresa_id', $empresaId)],
+            'valor' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
+            'meio_pagamento' => ['required', 'string', Rule::in(array_keys((array) config('faturacao.meios_pagamento', [])))],
+        ];
+    }
+}
diff --git a/Modules/Faturacao/app/Models/Fatura.php b/Modules/Faturacao/app/Models/Fatura.php
index 4c3890e..dfb0cc7 100644
--- a/Modules/Faturacao/app/Models/Fatura.php
+++ b/Modules/Faturacao/app/Models/Fatura.php
@@ -5,6 +5,7 @@
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
 use Illuminate\Database\Eloquent\Relations\HasMany;
+use Illuminate\Database\Eloquent\Relations\HasOne;
 use Modules\Core\Traits\BelongsToTenant;
 use Modules\Faturacao\Contracts\DocumentoFiscalInterface;
 use Modules\Faturacao\Support\Dinheiro;
@@ -66,6 +67,17 @@ public function linhas(): HasMany
         return $this->hasMany(FaturaLinha::class)->orderBy('ordem');
     }
 
+    public function recibos(): HasMany
+    {
+        return $this->hasMany(Recibo::class);
+    }
+
+    /** Fatura nova que corrige esta, quando a AGT a rejeitou (liga-se pelo número do documento rejeitado). */
+    public function correcao(): HasOne
+    {
+        return $this->hasOne(self::class, 'rejeitado_documento_no', 'numero_documento');
+    }
+
     public function notasCreditoDebito(): HasMany
     {
         return $this->hasMany(NotaCreditoDebito::class);
diff --git a/Modules/Faturacao/app/Providers/FaturacaoServiceProvider.php b/Modules/Faturacao/app/Providers/FaturacaoServiceProvider.php
index b5de559..518ed18 100644
--- a/Modules/Faturacao/app/Providers/FaturacaoServiceProvider.php
+++ b/Modules/Faturacao/app/Providers/FaturacaoServiceProvider.php
@@ -21,6 +21,8 @@ public function boot(): void
 
         $menu = $this->app->make(MenuRegistry::class);
         $menu->adicionar('faturacao.faturas.index', 'Faturas', 20);
+        $menu->adicionar('faturacao.notas.index', 'Notas de crédito e débito', 25);
+        $menu->adicionar('faturacao.recibos.index', 'Recibos', 27);
         $menu->adicionar('faturacao.clientes.index', 'Clientes', 30);
         $menu->adicionar('faturacao.produtos.index', 'Produtos', 40);
         $menu->adicionar('faturacao.saft.criar', 'SAF-T (AO)', 80);
diff --git a/Modules/Faturacao/app/Services/NotaCreditoDebitoService.php b/Modules/Faturacao/app/Services/NotaCreditoDebitoService.php
index 258aa24..38d40fd 100644
--- a/Modules/Faturacao/app/Services/NotaCreditoDebitoService.php
+++ b/Modules/Faturacao/app/Services/NotaCreditoDebitoService.php
@@ -72,6 +72,29 @@ protected function prepararAnulacaoTotal(Fatura $fatura, string $motivo): NotaCr
     /**
      * @param  array<int, array{descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
      */
+    /** Elimina uma nota ainda em rascunho; uma nota emitida é um documento fiscal e não se apaga. */
+    public function eliminarRascunho(NotaCreditoDebito $nota): void
+    {
+        DB::transaction(function () use ($nota) {
+            $atual = NotaCreditoDebito::withoutGlobalScopes()
+                ->where('empresa_id', $nota->empresa_id)
+                ->lockForUpdate()
+                ->findOrFail($nota->id);
+
+            if ($atual->estado !== 'rascunho') {
+                throw new \DomainException('Só é possível apagar notas em rascunho.');
+            }
+
+            $atual->linhas()->delete();
+            $atual->delete();
+        });
+
+        Log::info('Rascunho de nota eliminado', [
+            'nota_id' => $nota->id,
+            'empresa_id' => $nota->empresa_id,
+        ]);
+    }
+
     public function criarRascunho(
         int $empresaId,
         int $clienteId,
diff --git a/Modules/Faturacao/app/Services/ReciboService.php b/Modules/Faturacao/app/Services/ReciboService.php
index d1654fa..e7f77e8 100644
--- a/Modules/Faturacao/app/Services/ReciboService.php
+++ b/Modules/Faturacao/app/Services/ReciboService.php
@@ -133,7 +133,38 @@ protected function validarFatura(int $empresaId, int $clienteId, int $faturaId,
             throw new \DomainException('O cliente do recibo tem de ser o da fatura.');
         }
 
-        $jaRecebido = Recibo::withoutGlobalScopes()
+        [$jaRecebido, $creditado] = $this->recebidoECreditado($empresaId, $faturaId);
+
+        if ($jaRecebido + $centimos > Dinheiro::centimos($fatura->valor_total) - $creditado) {
+            throw new \DomainException('O total recebido excede o valor em dívida da fatura (já deduzidas as notas de crédito).');
+        }
+    }
+
+    /**
+     * Valor ainda em dívida de uma fatura, em cêntimos: total − recibos emitidos − notas de
+     * crédito emitidas. É a mesma conta que recusa recibos a mais.
+     */
+    public function saldoEmCentimos(Fatura $fatura): int
+    {
+        return $this->resumoDaFatura($fatura)['saldo'];
+    }
+
+    /** @return array{recebido: int, creditado: int, saldo: int} em cêntimos */
+    public function resumoDaFatura(Fatura $fatura): array
+    {
+        [$recebido, $creditado] = $this->recebidoECreditado((int) $fatura->empresa_id, (int) $fatura->id);
+
+        return [
+            'recebido' => $recebido,
+            'creditado' => $creditado,
+            'saldo' => Dinheiro::centimos($fatura->valor_total) - $recebido - $creditado,
+        ];
+    }
+
+    /** @return array{0: int, 1: int} [recebido, creditado] em cêntimos */
+    protected function recebidoECreditado(int $empresaId, int $faturaId): array
+    {
+        $recebido = Recibo::withoutGlobalScopes()
             ->where('empresa_id', $empresaId)
             ->where('fatura_id', $faturaId)
             ->where('estado', 'emitido')
@@ -148,8 +179,6 @@ protected function validarFatura(int $empresaId, int $clienteId, int $faturaId,
             ->get(['valor_total'])
             ->sum(fn ($nota) => Dinheiro::centimos($nota->valor_total));
 
-        if ($jaRecebido + $centimos > Dinheiro::centimos($fatura->valor_total) - $creditado) {
-            throw new \DomainException('O total recebido excede o valor em dívida da fatura (já deduzidas as notas de crédito).');
-        }
+        return [(int) $recebido, (int) $creditado];
     }
 }
diff --git a/Modules/Faturacao/app/Traits/AssinavelAgt.php b/Modules/Faturacao/app/Traits/AssinavelAgt.php
index 9990911..7f554b9 100644
--- a/Modules/Faturacao/app/Traits/AssinavelAgt.php
+++ b/Modules/Faturacao/app/Traits/AssinavelAgt.php
@@ -2,6 +2,7 @@
 
 namespace Modules\Faturacao\Traits;
 
+use Illuminate\Database\Eloquent\Relations\MorphOne;
 use Modules\Core\Models\Empresa;
 use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
 use Modules\Faturacao\Models\AgtSubmissao;
@@ -33,6 +34,12 @@ public static function bootAssinavelAgt(): void
         });
     }
 
+    /** Registo de envio deste documento à AGT (um por documento; só existe com a comunicação AGT ativa). */
+    public function agtSubmissao(): MorphOne
+    {
+        return $this->morphOne(AgtSubmissao::class, 'documento');
+    }
+
     /**
      * Congela no documento o NIF, o nome e o país do cliente. Chamado pelo
      * serviço de assinatura no momento da emissão, antes de construir o objecto
diff --git a/Modules/Faturacao/config/config.php b/Modules/Faturacao/config/config.php
index 3b710ac..aabcbbe 100644
--- a/Modules/Faturacao/config/config.php
+++ b/Modules/Faturacao/config/config.php
@@ -68,6 +68,17 @@
     | da AGT para o regime fiscal de cada empresa/produto; nem todos os bens
     | e serviços estão sujeitos à taxa geral.
     */
+    /*
+    | Meios de pagamento aceites nos recibos emitidos pela interface (código => rótulo).
+    | São os mesmos que o Atelier e o Estúdio já usam.
+    */
+    'meios_pagamento' => [
+        'dinheiro' => 'Dinheiro',
+        'transferencia' => 'Transferência bancária',
+        'multicaixa' => 'Multicaixa',
+        'outro' => 'Outro',
+    ],
+
     'taxa_iva_geral' => (float) env('FATURACAO_TAXA_IVA_GERAL', 14.0),
 
     /*
diff --git a/Modules/Faturacao/docs/MATRIZ_REQUISITOS_FISCAIS.md b/Modules/Faturacao/docs/MATRIZ_REQUISITOS_FISCAIS.md
index 5cc01d7..0cb7d21 100644
--- a/Modules/Faturacao/docs/MATRIZ_REQUISITOS_FISCAIS.md
+++ b/Modules/Faturacao/docs/MATRIZ_REQUISITOS_FISCAIS.md
@@ -39,3 +39,15 @@ ## Requisitos do Regime Jurídico das Facturas ainda por mapear
 distribuição fiscal; menções específicas por tipo de documento. ❔ Para cada um: ler o
 artigo aplicável, acrescentar uma linha a esta matriz com campo, validação, elemento
 assinado, elemento SAF-T e teste, e só então marcar ✅.
+
+## Interface
+
+| Capacidade | Estado | Teste |
+|---|---|---|
+| Faturas: criar, editar e eliminar rascunhos, emitir, anular | ✅ | RascunhoFaturaTest, FormularioFaturaIsencaoTest |
+| Notas de crédito/débito: rascunho, emissão, eliminação de rascunho, listas | ✅ | NotasInterfaceTest |
+| Recibos de faturas emitidas, com saldo | ✅ | RecibosInterfaceTest |
+| Estado AGT visível e correção de faturas rejeitadas | ✅ | EstadoAgtInterfaceTest |
+| Versão para impressão com o layout fiscal | ⛔ por validar contra a lei | — |
+| Recibos de adiantamento | ⛔ a AGT ainda não os suporta aqui | — |
+
diff --git a/Modules/Faturacao/resources/views/faturas/index.blade.php b/Modules/Faturacao/resources/views/faturas/index.blade.php
index a74111c..46f5868 100644
--- a/Modules/Faturacao/resources/views/faturas/index.blade.php
+++ b/Modules/Faturacao/resources/views/faturas/index.blade.php
@@ -30,6 +30,9 @@ class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover
                         <span class="text-xs {{ $fatura->estado === 'emitida' ? 'text-emerald-600' : 'text-amber-600' }}">
                             {{ ucfirst($fatura->estado) }}
                         </span>
+                        @if (config('faturacao.agt.ativo') && $fatura->estado === 'emitida')
+                            <span class="block text-xs text-slate-400">AGT: {{ $fatura->agtSubmissao?->rotulo() ?? 'sem registo de envio' }}</span>
+                        @endif
                     </div>
                 </a>
             @empty
diff --git a/Modules/Faturacao/resources/views/faturas/mostrar.blade.php b/Modules/Faturacao/resources/views/faturas/mostrar.blade.php
index b2caca1..589c849 100644
--- a/Modules/Faturacao/resources/views/faturas/mostrar.blade.php
+++ b/Modules/Faturacao/resources/views/faturas/mostrar.blade.php
@@ -29,6 +29,12 @@
             <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
         @endif
 
+        @if ($fatura->rejeitado_documento_no)
+            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
+                Esta fatura corrige o documento <strong>{{ $fatura->rejeitado_documento_no }}</strong>, que a AGT não aceitou.
+            </div>
+        @endif
+
         <table class="mt-6 w-full text-sm">
             <thead>
                 <tr class="border-b border-slate-200 text-left text-slate-500">
@@ -97,13 +103,62 @@
                 <p class="mt-1">Versão da chave: {{ $fatura->chave_versao }}</p>
             </div>
 
+            @include('faturacao::partials.estado-agt', ['documento' => $fatura])
+
+            @if ($fatura->correcao)
+                <div class="mt-4 rounded-md border border-slate-200 px-4 py-3 text-sm text-slate-600">
+                    Corrigida por
+                    <a href="{{ route('faturacao.faturas.mostrar', $fatura->correcao->id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">
+                        {{ $fatura->correcao->numero_documento ?? 'rascunho #'.$fatura->correcao->id }}
+                    </a>.
+                </div>
+            @elseif (config('faturacao.agt.ativo') && in_array($fatura->agtSubmissao?->estado, ['invalido', 'rejeitado'], true))
+                <form method="POST" action="{{ route('faturacao.faturas.corrigir', $fatura->id) }}" class="mt-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
+                    @csrf
+                    <p>A AGT não aceita reenviar este documento: a correção é uma fatura nova (com outro número) que segue como correção desta.</p>
+                    <button type="submit" class="mt-3 rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">
+                        Criar rascunho de correção
+                    </button>
+                </form>
+            @endif
+
+            @if ($resumo)
+                <div class="mt-4 rounded-lg border border-slate-200 p-4 text-sm">
+                    <p class="font-medium text-slate-700">Recebimentos</p>
+                    <div class="mt-2 space-y-1 text-slate-600">
+                        <div class="flex justify-between"><span>Recebido (recibos)</span><span>{{ number_format($resumo['recebido'] / 100, 2, ',', ' ') }}</span></div>
+                        <div class="flex justify-between"><span>Creditado (notas de crédito)</span><span>{{ number_format($resumo['creditado'] / 100, 2, ',', ' ') }}</span></div>
+                        <div class="flex justify-between border-t border-slate-200 pt-1 font-semibold text-slate-800"><span>Em dívida</span><span>{{ number_format($resumo['saldo'] / 100, 2, ',', ' ') }} {{ $fatura->moeda }}</span></div>
+                    </div>
+
+                    @if ($fatura->recibos->isNotEmpty())
+                        <ul class="mt-3 space-y-1 border-t border-slate-100 pt-2 text-slate-600">
+                            @foreach ($fatura->recibos as $recibo)
+                                <li class="flex justify-between">
+                                    <a href="{{ route('faturacao.recibos.mostrar', $recibo->id) }}" class="text-indigo-600 hover:text-indigo-500">{{ $recibo->numero_documento ?? 'Recibo #'.$recibo->id }}</a>
+                                    <span>{{ number_format((float) $recibo->valor, 2, ',', ' ') }}</span>
+                                </li>
+                            @endforeach
+                        </ul>
+                    @endif
+
+                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 pt-3 font-medium">
+                        @if ($resumo['saldo'] > 0)
+                            <a href="{{ route('faturacao.recibos.criar', ['fatura' => $fatura->id]) }}" class="text-indigo-600 hover:text-indigo-500">Registar recibo</a>
+                        @endif
+                        <a href="{{ route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $fatura->id]) }}" class="text-indigo-600 hover:text-indigo-500">Nota de crédito (parcial)</a>
+                        <a href="{{ route('faturacao.notas.criar', ['tipo' => 'debito', 'fatura' => $fatura->id]) }}" class="text-indigo-600 hover:text-indigo-500">Nota de débito</a>
+                    </div>
+                </div>
+            @endif
+
             @if ($fatura->notasCreditoDebito->isNotEmpty())
                 <div class="mt-4 rounded-lg border border-slate-200 p-4 text-sm">
                     <p class="font-medium text-slate-700">Notas associadas</p>
                     <ul class="mt-2 space-y-1 text-slate-600">
                         @foreach ($fatura->notasCreditoDebito as $nota)
                             <li class="flex justify-between">
-                                <span>{{ $nota->numero_documento ?? 'Rascunho #'.$nota->id }} — {{ $nota->tipo === 'credito' ? 'Crédito' : 'Débito' }} ({{ $nota->estado }})</span>
+                                <a href="{{ route('faturacao.notas.mostrar', $nota->id) }}" class="text-indigo-600 hover:text-indigo-500">{{ $nota->numero_documento ?? 'Rascunho #'.$nota->id }} — {{ $nota->tipo === 'credito' ? 'Crédito' : 'Débito' }} ({{ $nota->estado }})</a>
                                 <span>{{ number_format((float) $nota->valor_total, 2, ',', ' ') }}</span>
                             </li>
                         @endforeach
diff --git a/Modules/Faturacao/resources/views/notas/criar.blade.php b/Modules/Faturacao/resources/views/notas/criar.blade.php
new file mode 100644
index 0000000..a10e32b
--- /dev/null
+++ b/Modules/Faturacao/resources/views/notas/criar.blade.php
@@ -0,0 +1,132 @@
+@extends('core::layouts.app')
+
+@section('titulo', $tipo === 'credito' ? 'Nova nota de crédito' : 'Nova nota de débito')
+
+@section('conteudo')
+    <div class="w-full max-w-3xl" x-data="novaNota(@js($linhasIniciais), @js($tipo === 'credito'), @js($taxaNaInterface))">
+        <h1 class="text-xl font-semibold text-slate-900">{{ $tipo === 'credito' ? 'Nova nota de crédito' : 'Nova nota de débito' }}</h1>
+        <p class="mt-1 text-sm text-slate-500">
+            @if ($tipo === 'credito')
+                Parte das linhas da fatura {{ $fatura->numero_documento }}: reduz a quantidade (ou remove linhas) para creditar só uma parte.
+            @else
+                Uma nota de débito não liquida IVA. Fica como rascunho — só recebe número e assinatura fiscal quando a emitires.
+            @endif
+        </p>
+
+        @if ($errors->any())
+            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
+                <ul class="list-disc space-y-1 pl-4">
+                    @foreach ($errors->all() as $erro)
+                        <li>{{ $erro }}</li>
+                    @endforeach
+                </ul>
+            </div>
+        @endif
+
+        <form method="POST" action="{{ route('faturacao.notas.guardar') }}" class="mt-6 space-y-6">
+            @csrf
+            <input type="hidden" name="tipo" value="{{ $tipo }}">
+
+            @if ($fatura)
+                <input type="hidden" name="fatura_id" value="{{ $fatura->id }}">
+                <p class="rounded-md bg-slate-50 border border-slate-200 px-4 py-3 text-sm text-slate-600">
+                    Fatura de origem: <strong>{{ $fatura->numero_documento }}</strong> — {{ $fatura->cliente_nome ?? $fatura->cliente->nome }}
+                </p>
+            @else
+                <div>
+                    <label for="cliente_id" class="block text-sm font-medium text-slate-700">Cliente</label>
+                    <select name="cliente_id" id="cliente_id" required class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
+                        <option value="">Selecionar...</option>
+                        @foreach ($clientes as $cliente)
+                            <option value="{{ $cliente->id }}" @selected((int) old('cliente_id') === (int) $cliente->id)>{{ $cliente->nome }} @if($cliente->nif) ({{ $cliente->nif }}) @endif</option>
+                        @endforeach
+                    </select>
+                </div>
+            @endif
+
+            <div>
+                <label for="motivo" class="block text-sm font-medium text-slate-700">Motivo</label>
+                <input type="text" name="motivo" id="motivo" required minlength="3" maxlength="60" value="{{ old('motivo') }}"
+                       placeholder="{{ $tipo === 'credito' ? 'Ex.: devolução de mercadoria' : 'Ex.: repasse de despesas' }}"
+                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
+            </div>
+
+            <div>
+                <div class="flex items-center justify-between">
+                    <label class="block text-sm font-medium text-slate-700">Linhas</label>
+                    <button type="button" x-show="!credito" @click="adicionarLinha()" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">+ Linha</button>
+                </div>
+
+                <div class="mt-2 space-y-2">
+                    <template x-for="(linha, indice) in linhas" :key="indice">
+                        <div class="grid grid-cols-12 gap-2 rounded-md border border-slate-200 p-2">
+                            <input type="text" :name="`linhas[${indice}][descricao]`" x-model="linha.descricao" placeholder="Descrição" required
+                                   class="col-span-12 sm:col-span-5 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
+                            <input type="number" step="0.001" min="0.001" :name="`linhas[${indice}][quantidade]`" x-model.number="linha.quantidade" placeholder="Qtd"
+                                   class="col-span-4 sm:col-span-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
+                            <input type="number" step="0.01" min="0" :name="`linhas[${indice}][preco_unitario]`" x-model.number="linha.preco_unitario" placeholder="Preço"
+                                   class="col-span-4 sm:col-span-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
+                            <template x-if="comTaxa">
+                                {{-- Na nota de crédito a taxa é a da fatura: só leitura (segue no pedido). --}}
+                                <input type="number" step="0.01" min="0" max="100" :name="`linhas[${indice}][taxa_iva]`" x-model.number="linha.taxa_iva" placeholder="IVA %"
+                                       :readonly="credito" :class="credito ? 'bg-slate-100 text-slate-500' : ''"
+                                       class="col-span-3 sm:col-span-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
+                            </template>
+                            <input type="hidden" :name="`linhas[${indice}][produto_id]`" :value="linha.produto_id ?? ''">
+                            <input type="hidden" :name="`linhas[${indice}][motivo_isencao]`" :value="linha.motivo_isencao ?? ''">
+                            <button type="button" @click="removerLinha(indice)" class="col-span-1 text-red-500 hover:text-red-700" title="Remover">✕</button>
+                        </div>
+                    </template>
+                </div>
+            </div>
+
+            <div class="rounded-md bg-slate-50 border border-slate-200 p-4 text-sm">
+                <div class="flex justify-between"><span>Subtotal</span><span x-text="formatar(totalSemIva())"></span></div>
+                <div class="flex justify-between" x-show="comTaxa"><span>IVA</span><span x-text="formatar(totalIva())"></span></div>
+                <div class="mt-1 flex justify-between border-t border-slate-200 pt-1 font-semibold"><span>Total</span><span x-text="formatar(totalGeral())"></span></div>
+                <p class="mt-2 text-xs text-slate-500">Valores só para conferência: quem calcula e grava é sempre o servidor.</p>
+            </div>
+
+            <button type="submit" :disabled="linhas.length === 0"
+                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50">
+                Guardar rascunho
+            </button>
+        </form>
+    </div>
+
+    <script>
+        function novaNota(linhasIniciais = [], credito = false, comTaxa = false) {
+            const vazia = () => ({ descricao: '', quantidade: 1, preco_unitario: 0, taxa_iva: 0, motivo_isencao: '' });
+
+            return {
+                credito,
+                comTaxa,
+                linhas: linhasIniciais.length > 0 ? linhasIniciais : [vazia()],
+                adicionarLinha() {
+                    this.linhas.push(vazia());
+                },
+                removerLinha(indice) {
+                    this.linhas.splice(indice, 1);
+                },
+                totalSemIva() {
+                    return this.linhas.reduce((soma, l) => soma + (Number(l.quantidade) || 0) * (Number(l.preco_unitario) || 0), 0);
+                },
+                totalIva() {
+                    if (!this.comTaxa) {
+                        return 0;
+                    }
+                    return this.linhas.reduce((soma, l) => {
+                        const base = (Number(l.quantidade) || 0) * (Number(l.preco_unitario) || 0);
+                        return soma + base * ((Number(l.taxa_iva) || 0) / 100);
+                    }, 0);
+                },
+                totalGeral() {
+                    return this.totalSemIva() + this.totalIva();
+                },
+                formatar(valor) {
+                    return valor.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' AOA';
+                },
+            };
+        }
+    </script>
+@endsection
diff --git a/Modules/Faturacao/resources/views/notas/index.blade.php b/Modules/Faturacao/resources/views/notas/index.blade.php
new file mode 100644
index 0000000..a534632
--- /dev/null
+++ b/Modules/Faturacao/resources/views/notas/index.blade.php
@@ -0,0 +1,48 @@
+@extends('core::layouts.app')
+
+@section('titulo', 'Notas de crédito e débito')
+
+@section('conteudo')
+    <div class="w-full max-w-3xl">
+        <div class="flex items-center justify-between">
+            <h1 class="text-xl font-semibold text-slate-900">Notas de crédito e débito</h1>
+            <a href="{{ route('faturacao.notas.criar', ['tipo' => 'debito']) }}"
+               class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
+                Nova nota de débito
+            </a>
+        </div>
+        <p class="mt-1 text-sm text-slate-500">Uma nota de crédito parte sempre de uma fatura emitida: abre a fatura e escolhe «Nota de crédito».</p>
+
+        @if (session('erro'))
+            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
+        @endif
+        @if (session('sucesso'))
+            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
+        @endif
+
+        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
+            @forelse ($notas as $nota)
+                <a href="{{ route('faturacao.notas.mostrar', $nota->id) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-50">
+                    <div>
+                        <p class="text-sm font-medium text-slate-900">
+                            {{ $nota->numero_documento ?? 'Rascunho #'.$nota->id }}
+                            <span class="ml-1 text-xs font-normal text-slate-500">{{ $nota->tipo === 'credito' ? 'Crédito' : 'Débito' }}</span>
+                        </p>
+                        <p class="text-xs text-slate-500">{{ $nota->cliente->nome }} — {{ $nota->motivo }}</p>
+                    </div>
+                    <div class="text-right">
+                        <p class="text-sm font-medium text-slate-900">{{ number_format((float) $nota->valor_total, 2, ',', ' ') }} {{ $nota->moeda }}</p>
+                        <span class="text-xs {{ $nota->estado === 'emitida' ? 'text-emerald-600' : 'text-amber-600' }}">{{ ucfirst($nota->estado) }}</span>
+                        @if (config('faturacao.agt.ativo') && $nota->estado === 'emitida')
+                            <span class="block text-xs text-slate-400">AGT: {{ $nota->agtSubmissao?->rotulo() ?? 'sem registo de envio' }}</span>
+                        @endif
+                    </div>
+                </a>
+            @empty
+                <p class="px-4 py-6 text-center text-sm text-slate-500">Ainda não tens notas de crédito ou débito.</p>
+            @endforelse
+        </div>
+
+        {{ $notas->links() }}
+    </div>
+@endsection
diff --git a/Modules/Faturacao/resources/views/notas/mostrar.blade.php b/Modules/Faturacao/resources/views/notas/mostrar.blade.php
new file mode 100644
index 0000000..857ebed
--- /dev/null
+++ b/Modules/Faturacao/resources/views/notas/mostrar.blade.php
@@ -0,0 +1,112 @@
+@extends('core::layouts.app')
+
+@section('titulo', $nota->numero_documento ?? 'Rascunho de nota')
+
+@section('conteudo')
+    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
+        <div class="flex items-start justify-between">
+            <div>
+                <h1 class="text-xl font-semibold text-slate-900">
+                    {{ $nota->numero_documento ?? 'Rascunho #'.$nota->id }}
+                    <span class="ml-1 text-sm font-normal text-slate-500">{{ $nota->tipo === 'credito' ? 'Nota de crédito' : 'Nota de débito' }}</span>
+                </h1>
+                <p class="text-sm text-slate-500">
+                    @php
+                        $clienteNome = $nota->cliente_nome ?? $nota->cliente->nome;
+                        $clienteNif = $nota->cliente_nome !== null ? $nota->cliente_nif : $nota->cliente->nif;
+                    @endphp
+                    {{ $clienteNome }} @if($clienteNif) — NIF {{ $clienteNif }} @endif
+                </p>
+                @if ($nota->fatura)
+                    <p class="mt-1 text-sm text-slate-500">
+                        Fatura de origem:
+                        <a href="{{ route('faturacao.faturas.mostrar', $nota->fatura->id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">{{ $nota->fatura->numero_documento }}</a>
+                    </p>
+                @endif
+                <p class="mt-1 text-sm text-slate-500">Motivo: {{ $nota->motivo }}</p>
+            </div>
+            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $nota->estado === 'emitida' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
+                {{ ucfirst($nota->estado) }}
+            </span>
+        </div>
+
+        @if (session('sucesso'))
+            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
+        @endif
+        @if (session('erro'))
+            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
+        @endif
+
+        <table class="mt-6 w-full text-sm">
+            <thead>
+                <tr class="border-b border-slate-200 text-left text-slate-500">
+                    <th class="py-2">Descrição</th>
+                    <th class="py-2 text-right">Qtd</th>
+                    <th class="py-2 text-right">Preço</th>
+                    <th class="py-2 text-right">IVA</th>
+                    <th class="py-2 text-right">Total</th>
+                </tr>
+            </thead>
+            <tbody>
+                @foreach ($nota->linhas as $linha)
+                    <tr class="border-b border-slate-100">
+                        <td class="py-2">{{ $linha->descricao }}</td>
+                        <td class="py-2 text-right">{{ rtrim(rtrim($linha->quantidade, '0'), '.') }}</td>
+                        <td class="py-2 text-right">{{ number_format((float) $linha->preco_unitario, 2, ',', ' ') }}</td>
+                        <td class="py-2 text-right">{{ rtrim(rtrim($linha->taxa_iva, '0'), '.') ?: '0' }}%</td>
+                        <td class="py-2 text-right">{{ number_format((float) $linha->valor_total, 2, ',', ' ') }}</td>
+                    </tr>
+                @endforeach
+            </tbody>
+        </table>
+
+        <div class="mt-4 flex justify-end">
+            <div class="w-48 text-sm">
+                <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span>{{ number_format((float) $nota->valor_sem_iva, 2, ',', ' ') }}</span></div>
+                <div class="flex justify-between"><span class="text-slate-500">IVA</span><span>{{ number_format((float) $nota->valor_iva, 2, ',', ' ') }}</span></div>
+                <div class="flex justify-between border-t border-slate-200 pt-1 font-semibold"><span>Total</span><span>{{ number_format((float) $nota->valor_total, 2, ',', ' ') }} {{ $nota->moeda }}</span></div>
+            </div>
+        </div>
+
+        @if (config('faturacao.agt.ativo') && $nota->estaEmitido() && $nota->urlQrAgt())
+            @php
+                $qrImagem = \Modules\Faturacao\Support\QrCodeAgt::dataUri($nota->urlQrAgt());
+            @endphp
+            <div class="mt-6 flex items-center gap-4 border-t border-slate-200 pt-4">
+                @if ($qrImagem)
+                    <img src="{{ $qrImagem }}" alt="QR Code da AGT" width="175" height="175">
+                @endif
+                <p class="text-xs text-slate-500">Consulte este documento no portal da AGT: {{ $nota->urlQrAgt() }}</p>
+            </div>
+        @endif
+
+        @if ($nota->estado === 'rascunho')
+            <form method="POST" action="{{ route('faturacao.notas.emitir', $nota->id) }}" class="mt-6">
+                @csrf
+                <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
+                    Emitir nota
+                </button>
+                <p class="mt-2 text-center text-xs text-slate-500">Depois de emitida, esta nota não pode ser alterada nem apagada.</p>
+            </form>
+
+            <div class="mt-4 flex items-center justify-end border-t border-slate-200 pt-4 text-sm">
+                <form method="POST" action="{{ route('faturacao.notas.eliminar', $nota->id) }}"
+                      onsubmit="return confirm('Eliminar este rascunho? Esta ação não pode ser desfeita.');">
+                    @csrf
+                    @method('DELETE')
+                    <button type="submit" class="font-medium text-red-600 hover:text-red-500">Eliminar rascunho</button>
+                </form>
+            </div>
+        @else
+            <div class="mt-6 rounded-lg bg-slate-50 border border-slate-200 p-4 font-mono text-xs text-slate-500">
+                <p>Data/hora do sistema: {{ $nota->data_hora_sistema->format('Y-m-d H:i:s') }}</p>
+                <p class="mt-1 break-all">Hash: {{ Str::limit($nota->hash, 60) }}</p>
+                <p class="mt-1">Versão da chave: {{ $nota->chave_versao }}</p>
+            </div>
+
+            @include('faturacao::partials.estado-agt', ['documento' => $nota])
+        @endif
+
+        <p class="mt-6 text-sm"><a href="{{ route('faturacao.notas.index') }}" class="font-medium text-indigo-600 hover:text-indigo-500">← Notas de crédito e débito</a></p>
+    </div>
+@endsection
diff --git a/Modules/Faturacao/resources/views/partials/estado-agt.blade.php b/Modules/Faturacao/resources/views/partials/estado-agt.blade.php
new file mode 100644
index 0000000..3135a98
--- /dev/null
+++ b/Modules/Faturacao/resources/views/partials/estado-agt.blade.php
@@ -0,0 +1,33 @@
+{{-- Estado do documento na AGT. Só aparece com a comunicação AGT ativa e para documentos emitidos.
+     "Enviado" não é confirmação fiscal: só «Válido» o é. Uso: @include('faturacao::partials.estado-agt', ['documento' => $x]) --}}
+@if (config('faturacao.agt.ativo') && $documento->estaEmitido())
+    @php
+        $submissao = $documento->agtSubmissao;
+        $estadoAgt = $submissao?->estado;
+        $coresAgt = [
+            'valido' => 'bg-emerald-100 text-emerald-700',
+            'invalido' => 'bg-red-100 text-red-700',
+            'rejeitado' => 'bg-red-100 text-red-700',
+            'erro' => 'bg-amber-100 text-amber-700',
+            'aguarda_intervencao' => 'bg-amber-100 text-amber-700',
+        ];
+    @endphp
+    <div class="mt-4 rounded-lg border border-slate-200 p-4 text-sm">
+        <div class="flex items-center justify-between gap-3">
+            <span class="font-medium text-slate-700">Estado na AGT</span>
+            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $coresAgt[$estadoAgt] ?? 'bg-slate-100 text-slate-700' }}">
+                {{ $submissao?->rotulo() ?? 'Sem registo de envio' }}
+            </span>
+        </div>
+        @if (! $submissao?->confirmadoFiscalmente())
+            <p class="mt-2 text-xs text-slate-500">Enquanto o estado não for «Válido», a AGT ainda não confirmou este documento.</p>
+        @endif
+        @if ($submissao && ! empty($submissao->erros) && $estadoAgt !== 'valido')
+            <ul class="mt-2 space-y-1 text-xs text-red-700">
+                @foreach (array_slice((array) $submissao->erros, 0, 5) as $erroAgt)
+                    <li>{{ is_array($erroAgt) ? trim(($erroAgt['codigo'] ?? '').' '.($erroAgt['descricao'] ?? '')) : $erroAgt }}</li>
+                @endforeach
+            </ul>
+        @endif
+    </div>
+@endif
diff --git a/Modules/Faturacao/resources/views/recibos/criar.blade.php b/Modules/Faturacao/resources/views/recibos/criar.blade.php
new file mode 100644
index 0000000..6bb223c
--- /dev/null
+++ b/Modules/Faturacao/resources/views/recibos/criar.blade.php
@@ -0,0 +1,57 @@
+@extends('core::layouts.app')
+
+@section('titulo', 'Registar recibo')
+
+@section('conteudo')
+    <div class="w-full max-w-xl">
+        <h1 class="text-xl font-semibold text-slate-900">Registar recibo</h1>
+        <p class="mt-1 text-sm text-slate-500">
+            Fatura <strong>{{ $fatura->numero_documento }}</strong> — {{ $fatura->cliente_nome ?? $fatura->cliente->nome }}.
+            O recibo é emitido logo (recebe número e assinatura fiscal) e não pode ser alterado nem apagado.
+        </p>
+
+        @if ($errors->any())
+            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
+                <ul class="list-disc space-y-1 pl-4">
+                    @foreach ($errors->all() as $erro)
+                        <li>{{ $erro }}</li>
+                    @endforeach
+                </ul>
+            </div>
+        @endif
+
+        <div class="mt-4 rounded-md bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
+            <div class="flex justify-between"><span>Total da fatura</span><span>{{ number_format((float) $fatura->valor_total, 2, ',', ' ') }} {{ $fatura->moeda }}</span></div>
+            <div class="flex justify-between"><span>Já recebido</span><span>{{ number_format($resumo['recebido'] / 100, 2, ',', ' ') }}</span></div>
+            <div class="flex justify-between"><span>Notas de crédito</span><span>{{ number_format($resumo['creditado'] / 100, 2, ',', ' ') }}</span></div>
+            <div class="mt-1 flex justify-between border-t border-slate-200 pt-1 font-semibold text-slate-800"><span>Em dívida</span><span>{{ number_format($resumo['saldo'] / 100, 2, ',', ' ') }} {{ $fatura->moeda }}</span></div>
+        </div>
+
+        <form method="POST" action="{{ route('faturacao.recibos.guardar') }}" class="mt-6 space-y-5"
+              onsubmit="return confirm('Emitir este recibo? Depois de emitido não pode ser alterado nem apagado.');">
+            @csrf
+            <input type="hidden" name="fatura_id" value="{{ $fatura->id }}">
+
+            <div>
+                <label for="valor" class="block text-sm font-medium text-slate-700">Valor recebido ({{ $fatura->moeda }})</label>
+                <input type="number" step="0.01" min="0.01" name="valor" id="valor" required value="{{ old('valor', $saldoSugerido) }}"
+                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
+            </div>
+
+            <div>
+                <label for="meio_pagamento" class="block text-sm font-medium text-slate-700">Meio de pagamento</label>
+                <select name="meio_pagamento" id="meio_pagamento" required class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
+                    @foreach ($meiosPagamento as $codigo => $rotulo)
+                        <option value="{{ $codigo }}" @selected(old('meio_pagamento') === $codigo)>{{ $rotulo }}</option>
+                    @endforeach
+                </select>
+            </div>
+
+            <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
+                Emitir recibo
+            </button>
+        </form>
+
+        <p class="mt-4 text-sm"><a href="{{ route('faturacao.faturas.mostrar', $fatura->id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">← Voltar à fatura</a></p>
+    </div>
+@endsection
diff --git a/Modules/Faturacao/resources/views/recibos/index.blade.php b/Modules/Faturacao/resources/views/recibos/index.blade.php
new file mode 100644
index 0000000..1806a8a
--- /dev/null
+++ b/Modules/Faturacao/resources/views/recibos/index.blade.php
@@ -0,0 +1,39 @@
+@extends('core::layouts.app')
+
+@section('titulo', 'Recibos')
+
+@section('conteudo')
+    <div class="w-full max-w-3xl">
+        <h1 class="text-xl font-semibold text-slate-900">Recibos</h1>
+        <p class="mt-1 text-sm text-slate-500">Um recibo regista um pagamento de uma fatura emitida: abre a fatura e escolhe «Registar recibo».</p>
+
+        @if (session('erro'))
+            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
+        @endif
+
+        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
+            @forelse ($recibos as $recibo)
+                <a href="{{ route('faturacao.recibos.mostrar', $recibo->id) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-50">
+                    <div>
+                        <p class="text-sm font-medium text-slate-900">{{ $recibo->numero_documento ?? 'Recibo #'.$recibo->id }}</p>
+                        <p class="text-xs text-slate-500">
+                            {{ $recibo->cliente->nome }}
+                            @if ($recibo->fatura) — fatura {{ $recibo->fatura->numero_documento }} @endif
+                        </p>
+                    </div>
+                    <div class="text-right">
+                        <p class="text-sm font-medium text-slate-900">{{ number_format((float) $recibo->valor, 2, ',', ' ') }} {{ $recibo->moeda }}</p>
+                        <span class="text-xs text-slate-500">{{ config('faturacao.meios_pagamento.'.$recibo->meio_pagamento, $recibo->meio_pagamento) }}</span>
+                        @if (config('faturacao.agt.ativo') && $recibo->estaEmitido())
+                            <span class="block text-xs text-slate-400">AGT: {{ $recibo->agtSubmissao?->rotulo() ?? 'sem registo de envio' }}</span>
+                        @endif
+                    </div>
+                </a>
+            @empty
+                <p class="px-4 py-6 text-center text-sm text-slate-500">Ainda não tens recibos.</p>
+            @endforelse
+        </div>
+
+        {{ $recibos->links() }}
+    </div>
+@endsection
diff --git a/Modules/Faturacao/resources/views/recibos/mostrar.blade.php b/Modules/Faturacao/resources/views/recibos/mostrar.blade.php
new file mode 100644
index 0000000..defa15b
--- /dev/null
+++ b/Modules/Faturacao/resources/views/recibos/mostrar.blade.php
@@ -0,0 +1,65 @@
+@extends('core::layouts.app')
+
+@section('titulo', $recibo->numero_documento ?? 'Recibo')
+
+@section('conteudo')
+    <div class="w-full max-w-xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
+        <div class="flex items-start justify-between">
+            <div>
+                <h1 class="text-xl font-semibold text-slate-900">
+                    {{ $recibo->numero_documento ?? 'Recibo #'.$recibo->id }}
+                    <span class="ml-1 text-sm font-normal text-slate-500">Recibo</span>
+                </h1>
+                <p class="text-sm text-slate-500">
+                    @php
+                        $clienteNome = $recibo->cliente_nome ?? $recibo->cliente->nome;
+                        $clienteNif = $recibo->cliente_nome !== null ? $recibo->cliente_nif : $recibo->cliente->nif;
+                    @endphp
+                    {{ $clienteNome }} @if($clienteNif) — NIF {{ $clienteNif }} @endif
+                </p>
+                @if ($recibo->fatura)
+                    <p class="mt-1 text-sm text-slate-500">
+                        Fatura:
+                        <a href="{{ route('faturacao.faturas.mostrar', $recibo->fatura->id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">{{ $recibo->fatura->numero_documento }}</a>
+                    </p>
+                @endif
+            </div>
+            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $recibo->estaEmitido() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
+                {{ ucfirst($recibo->estado) }}
+            </span>
+        </div>
+
+        @if (session('sucesso'))
+            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
+        @endif
+
+        <div class="mt-6 rounded-md bg-slate-50 border border-slate-200 p-4 text-sm">
+            <div class="flex justify-between"><span class="text-slate-500">Valor recebido</span><span class="font-semibold">{{ number_format((float) $recibo->valor, 2, ',', ' ') }} {{ $recibo->moeda }}</span></div>
+            <div class="mt-1 flex justify-between"><span class="text-slate-500">Meio de pagamento</span><span>{{ config('faturacao.meios_pagamento.'.$recibo->meio_pagamento, $recibo->meio_pagamento) }}</span></div>
+        </div>
+
+        @if (config('faturacao.agt.ativo') && $recibo->estaEmitido() && $recibo->urlQrAgt())
+            @php
+                $qrImagem = \Modules\Faturacao\Support\QrCodeAgt::dataUri($recibo->urlQrAgt());
+            @endphp
+            <div class="mt-6 flex items-center gap-4 border-t border-slate-200 pt-4">
+                @if ($qrImagem)
+                    <img src="{{ $qrImagem }}" alt="QR Code da AGT" width="175" height="175">
+                @endif
+                <p class="text-xs text-slate-500">Consulte este documento no portal da AGT: {{ $recibo->urlQrAgt() }}</p>
+            </div>
+        @endif
+
+        @if ($recibo->estaEmitido())
+            <div class="mt-6 rounded-lg bg-slate-50 border border-slate-200 p-4 font-mono text-xs text-slate-500">
+                <p>Data/hora do sistema: {{ $recibo->data_hora_sistema->format('Y-m-d H:i:s') }}</p>
+                <p class="mt-1 break-all">Hash: {{ Str::limit($recibo->hash, 60) }}</p>
+                <p class="mt-1">Versão da chave: {{ $recibo->chave_versao }}</p>
+            </div>
+
+            @include('faturacao::partials.estado-agt', ['documento' => $recibo])
+        @endif
+
+        <p class="mt-6 text-sm"><a href="{{ route('faturacao.recibos.index') }}" class="font-medium text-indigo-600 hover:text-indigo-500">← Recibos</a></p>
+    </div>
+@endsection
diff --git a/Modules/Faturacao/routes/web.php b/Modules/Faturacao/routes/web.php
index ad3e25f..2fe0d8f 100644
--- a/Modules/Faturacao/routes/web.php
+++ b/Modules/Faturacao/routes/web.php
@@ -3,7 +3,9 @@
 use Illuminate\Support\Facades\Route;
 use Modules\Faturacao\Http\Controllers\ClienteController;
 use Modules\Faturacao\Http\Controllers\FaturaController;
+use Modules\Faturacao\Http\Controllers\NotaController;
 use Modules\Faturacao\Http\Controllers\ProdutoController;
+use Modules\Faturacao\Http\Controllers\ReciboController;
 use Modules\Faturacao\Http\Controllers\SafTExportController;
 
 /*
@@ -30,6 +32,17 @@
         Route::delete('/faturas/{fatura}', [FaturaController::class, 'eliminar'])
             ->whereNumber('fatura')->middleware('throttle:20,1')->name('faturas.eliminar');
 
+        // Notas de crédito/débito: o rascunho é livre (como nas faturas); emitir é de Administrador.
+        Route::get('/notas', [NotaController::class, 'index'])->name('notas.index');
+        Route::get('/notas/criar', [NotaController::class, 'criar'])->name('notas.criar');
+        Route::post('/notas', [NotaController::class, 'guardar'])->middleware('throttle:30,1')->name('notas.guardar');
+        Route::get('/notas/{nota}', [NotaController::class, 'mostrar'])->whereNumber('nota')->name('notas.mostrar');
+        Route::delete('/notas/{nota}', [NotaController::class, 'eliminar'])
+            ->whereNumber('nota')->middleware('throttle:20,1')->name('notas.eliminar');
+
+        Route::get('/recibos', [ReciboController::class, 'index'])->name('recibos.index');
+        Route::get('/recibos/{recibo}', [ReciboController::class, 'mostrar'])->whereNumber('recibo')->name('recibos.mostrar');
+
         Route::middleware('papel:Administrador')->group(function () {
             Route::post('/faturas/{fatura}/emitir', [FaturaController::class, 'emitir'])
                 ->whereNumber('fatura')->middleware('throttle:20,1')->name('faturas.emitir');
@@ -37,6 +50,16 @@
             Route::post('/faturas/{fatura}/anular', [FaturaController::class, 'anular'])
                 ->whereNumber('fatura')->middleware('throttle:10,1')->name('faturas.anular');
 
+            Route::post('/faturas/{fatura}/corrigir', [FaturaController::class, 'corrigir'])
+                ->whereNumber('fatura')->middleware('throttle:10,1')->name('faturas.corrigir');
+
+            Route::post('/notas/{nota}/emitir', [NotaController::class, 'emitir'])
+                ->whereNumber('nota')->middleware('throttle:20,1')->name('notas.emitir');
+
+            // O recibo é emitido logo, sem rascunho: criar e guardar são de Administrador.
+            Route::get('/recibos/criar', [ReciboController::class, 'criar'])->name('recibos.criar');
+            Route::post('/recibos', [ReciboController::class, 'guardar'])->middleware('throttle:20,1')->name('recibos.guardar');
+
             Route::get('/saft', [SafTExportController::class, 'criar'])->name('saft.criar');
             Route::post('/saft', [SafTExportController::class, 'despachar'])->middleware('throttle:3,1')->name('saft.despachar');
             Route::get('/saft/{ficheiro}', [SafTExportController::class, 'descarregar'])
diff --git a/Modules/Faturacao/tests/Feature/EstadoAgtInterfaceTest.php b/Modules/Faturacao/tests/Feature/EstadoAgtInterfaceTest.php
new file mode 100644
index 0000000..59e43cf
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/EstadoAgtInterfaceTest.php
@@ -0,0 +1,215 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Http\Client\Request;
+use Illuminate\Support\Facades\Http;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Models\User;
+use Modules\Core\Support\Servico;
+use Modules\Faturacao\Models\AgtSubmissao;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Services\AgtSerieService;
+use Modules\Faturacao\Services\FaturaService;
+use Spatie\Permission\Models\Role;
+use Spatie\Permission\PermissionRegistrar;
+use Tests\TestCase;
+
+/**
+ * Estado do documento na AGT à vista (só «Válido» é confirmação fiscal) e correção, pela
+ * interface, de uma fatura que a AGT marcou como inválida ou rejeitada.
+ */
+class EstadoAgtInterfaceTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
+
+    protected User $admin;
+
+    protected User $operador;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->withoutVite();
+
+        $dir = sys_get_temp_dir().'/agt-chaves-'.uniqid();
+        mkdir($dir);
+        $privadaEmpresa = $this->gerarChave($dir.'/5001636863.pem');
+        $privadaSoftware = $this->gerarChave(sys_get_temp_dir().'/agt-sw-'.uniqid().'.pem');
+        $this->beforeApplicationDestroyed(function () use ($dir, $privadaSoftware) {
+            @unlink("{$dir}/5001636863.pem");
+            @rmdir($dir);
+            @unlink($privadaSoftware);
+        });
+
+        config([
+            'faturacao.chave_privada_path' => $privadaEmpresa,
+            'faturacao.chaves_dir' => $dir,
+            'faturacao.agt.ativo' => true,
+            'faturacao.agt.ambiente' => 'homologacao',
+            'faturacao.agt.utilizador' => 'produtor',
+            'faturacao.agt.palavra_passe' => 's3nh@',
+            'faturacao.agt.software.chave_privada_path' => $privadaSoftware,
+            'faturacao.agt.software.signature_version' => null,
+            'faturacao.saft_numero_certificado' => 'C_134',
+        ]);
+
+        Http::fake([
+            '*solicitarSerie' => fn (Request $r) => Http::response([
+                'resultCode' => 1,
+                'errorList' => [''],
+                'seriesFEResult' => ['seriesCode' => $r['documentType'].'6325S2C', 'authorizedQuantity' => '1000', 'firstDocumentNo' => '1', 'lastDocumentNo' => '1000'],
+            ], 200),
+            '*registarFactura' => Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
+        ]);
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
+        $this->empresa->aderirServicos([Servico::Faturacao]);
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);
+
+        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);
+
+        $this->admin = $this->criarUtilizador('admin@agt.ao', true);
+        $this->operador = $this->criarUtilizador('operador@agt.ao', false);
+    }
+
+    protected function gerarChave(string $caminho): string
+    {
+        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
+        openssl_pkey_export($recurso, $pem);
+        file_put_contents($caminho, $pem);
+
+        return $caminho;
+    }
+
+    protected function criarUtilizador(string $email, bool $administrador): User
+    {
+        $utilizador = User::create(['empresa_id' => $this->empresa->id, 'name' => $email, 'email' => $email, 'password' => 'Senha-Forte-123!']);
+
+        if ($administrador) {
+            app(PermissionRegistrar::class)->setPermissionsTeamId($this->empresa->id);
+            $utilizador->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $this->empresa->id]));
+        }
+
+        return $utilizador;
+    }
+
+    protected function faturaEmitida(): Fatura
+    {
+        $faturas = app(FaturaService::class);
+
+        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Serviço Alfa', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
+        ]));
+    }
+
+    protected function definirEstado(Fatura $fatura, string $estado, array $erros = []): void
+    {
+        AgtSubmissao::query()->where('documento_id', $fatura->id)->firstOrFail()->update(['estado' => $estado, 'erros' => $erros]);
+    }
+
+    public function test_pendente_nao_e_confirmacao_fiscal(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertOk()
+            ->assertSee('Estado na AGT')
+            ->assertSee('Pendente de envio')
+            ->assertSee('ainda não confirmou');
+    }
+
+    public function test_so_valido_e_confirmado_e_sem_botao_de_correcao(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->definirEstado($fatura, AgtSubmissao::VALIDO);
+
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertOk()
+            ->assertSee('Estado na AGT')
+            ->assertDontSee('ainda não confirmou')
+            ->assertDontSee('Criar rascunho de correção');
+    }
+
+    public function test_invalida_mostra_os_erros_e_o_botao_de_correcao(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->definirEstado($fatura, AgtSubmissao::INVALIDO, [['codigo' => 'E21', 'descricao' => 'Valor incoerente na linha 1']]);
+
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertOk()
+            ->assertSee('E21 Valor incoerente na linha 1')
+            ->assertSee('Criar rascunho de correção');
+    }
+
+    public function test_corrigir_cria_o_rascunho_e_liga_os_dois_documentos(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->definirEstado($fatura, AgtSubmissao::REJEITADO, [['codigo' => 'E07', 'descricao' => 'Rejeitada']]);
+
+        $resposta = $this->actingAs($this->admin)->post(route('faturacao.faturas.corrigir', $fatura->id));
+
+        $rascunho = Fatura::withoutGlobalScopes()->whereNotNull('rejeitado_documento_no')->firstOrFail();
+        $resposta->assertRedirect(route('faturacao.faturas.editar', $rascunho->id))->assertSessionHas('sucesso');
+        $this->assertSame($fatura->numero_documento, $rascunho->rejeitado_documento_no);
+        $this->assertSame('rascunho', $rascunho->estado);
+
+        // O rascunho diz o que corrige; a fatura rejeitada passa a apontar para ele, sem novo botão.
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $rascunho->id))
+            ->assertOk()->assertSee('corrige o documento')->assertSee($fatura->numero_documento);
+
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertOk()->assertSee('Corrigida por')->assertSee('rascunho #'.$rascunho->id)->assertDontSee('Criar rascunho de correção');
+
+        // Clicar outra vez não duplica nada.
+        $this->actingAs($this->admin)->post(route('faturacao.faturas.corrigir', $fatura->id))->assertRedirect(route('faturacao.faturas.editar', $rascunho->id));
+        $this->assertSame(2, Fatura::withoutGlobalScopes()->count());
+
+        // Emitida, a correção ganha número novo e passa a ser a "Corrigida por" com o número.
+        $this->actingAs($this->admin)->post(route('faturacao.faturas.emitir', $rascunho->id))->assertSessionHas('sucesso');
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertOk()->assertSee($rascunho->fresh()->numero_documento);
+    }
+
+    public function test_so_documentos_invalidos_ou_rejeitados_se_corrigem(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->admin)->post(route('faturacao.faturas.corrigir', $fatura->id))->assertSessionHas('erro');
+        $this->assertSame(1, Fatura::withoutGlobalScopes()->count());
+    }
+
+    public function test_so_o_administrador_corrige(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->definirEstado($fatura, AgtSubmissao::INVALIDO);
+
+        $this->actingAs($this->operador)->post(route('faturacao.faturas.corrigir', $fatura->id))->assertForbidden();
+        $this->assertSame(1, Fatura::withoutGlobalScopes()->count());
+    }
+
+    public function test_sem_comunicacao_agt_nao_ha_caixa_de_estado(): void
+    {
+        $fatura = $this->faturaEmitida();
+        config(['faturacao.agt.ativo' => false]);
+
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertOk()->assertDontSee('Estado na AGT');
+    }
+
+    public function test_as_listas_mostram_o_estado_na_agt(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->definirEstado($fatura, AgtSubmissao::VALIDO);
+
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.index'))
+            ->assertOk()->assertSee('AGT: Válido');
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/FormularioFaturaIsencaoTest.php b/Modules/Faturacao/tests/Feature/FormularioFaturaIsencaoTest.php
index ad181ba..cc38e89 100644
--- a/Modules/Faturacao/tests/Feature/FormularioFaturaIsencaoTest.php
+++ b/Modules/Faturacao/tests/Feature/FormularioFaturaIsencaoTest.php
@@ -147,4 +147,15 @@ public function test_atualizar_o_rascunho_guarda_o_novo_motivo_de_isencao(): voi
 
         $this->assertSame(['M10'], $fatura->linhas()->pluck('motivo_isencao')->all());
     }
+
+    public function test_depois_de_um_erro_o_formulario_conserva_as_linhas_escritas(): void
+    {
+        $volta = route('faturacao.faturas.criar');
+
+        $this->actingAs($this->admin)->from($volta)->post(route('faturacao.faturas.guardar'), $this->pedido(['descricao' => 'Linha-a-reter-ABC']))
+            ->assertRedirect($volta)
+            ->assertSessionHasErrors(['fatura']);
+
+        $this->actingAs($this->admin)->get($volta)->assertOk()->assertSee('Linha-a-reter-ABC');
+    }
 }
diff --git a/Modules/Faturacao/tests/Feature/NotasInterfaceTest.php b/Modules/Faturacao/tests/Feature/NotasInterfaceTest.php
new file mode 100644
index 0000000..e3d98ad
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/NotasInterfaceTest.php
@@ -0,0 +1,275 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Models\User;
+use Modules\Core\Support\Servico;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\NotaCreditoDebito;
+use Modules\Faturacao\Services\FaturaService;
+use Spatie\Permission\Models\Role;
+use Spatie\Permission\PermissionRegistrar;
+use Tests\TestCase;
+
+/** Notas de crédito e de débito pela interface: rascunho, emissão só de Administrador, erros sem 500. */
+class NotasInterfaceTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
+
+    protected User $admin;
+
+    protected User $operador;
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
+        config([
+            'faturacao.chave_privada_path' => $caminho,
+            'faturacao.agt.ativo' => false,
+            'faturacao.nota_debito_liquida_iva' => false,
+        ]);
+
+        $this->empresa = $this->criarEmpresa('Loja Notas', '5001636863');
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Notas', 'nif' => null]);
+        $this->admin = $this->criarUtilizador($this->empresa, 'admin@notas.ao', true);
+        $this->operador = $this->criarUtilizador($this->empresa, 'operador@notas.ao', false);
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
+    protected function criarUtilizador(Empresa $empresa, string $email, bool $administrador): User
+    {
+        $utilizador = User::create(['empresa_id' => $empresa->id, 'name' => $email, 'email' => $email, 'password' => 'Senha-Forte-123!']);
+
+        if ($administrador) {
+            app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
+            $utilizador->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $empresa->id]));
+        }
+
+        return $utilizador;
+    }
+
+    protected function faturaEmitida(): Fatura
+    {
+        $faturas = app(FaturaService::class);
+
+        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Serviço Alfa', 'quantidade' => 2, 'preco_unitario' => '500.00', 'taxa_iva' => 14],
+        ]));
+    }
+
+    /** @return array<string, mixed> */
+    protected function pedidoCredito(Fatura $fatura, array $linha = []): array
+    {
+        return [
+            'tipo' => 'credito',
+            'fatura_id' => $fatura->id,
+            'motivo' => 'Devolução parcial',
+            'linhas' => [array_merge(['descricao' => 'Serviço Alfa', 'quantidade' => 1, 'preco_unitario' => '500.00', 'taxa_iva' => 14], $linha)],
+        ];
+    }
+
+    public function test_formulario_da_nota_de_credito_parte_das_linhas_da_fatura(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->admin)->get(route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $fatura->id]))
+            ->assertOk()
+            ->assertSee('Nova nota de crédito')
+            ->assertSee($fatura->numero_documento)
+            ->assertSee('Serviço Alfa');
+    }
+
+    public function test_nota_de_credito_exige_uma_fatura_emitida(): void
+    {
+        $this->actingAs($this->admin)->get(route('faturacao.notas.criar', ['tipo' => 'credito']))
+            ->assertRedirect(route('faturacao.faturas.index'))
+            ->assertSessionHas('erro');
+
+        $rascunho = app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Ainda rascunho', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => 14],
+        ]);
+
+        $this->actingAs($this->admin)->get(route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $rascunho->id]))
+            ->assertRedirect(route('faturacao.faturas.index'))
+            ->assertSessionHas('erro');
+    }
+
+    public function test_credito_parcial_guarda_rascunho_e_o_administrador_emite(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura))
+            ->assertSessionHasNoErrors()
+            ->assertRedirect();
+
+        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
+        $this->assertSame('rascunho', $nota->estado);
+        $this->assertSame('credito', $nota->tipo);
+        $this->assertSame('570.00', $nota->valor_total); // 500 + 14% de IVA
+        $this->assertSame($fatura->cliente_id, $nota->cliente_id);
+
+        $this->actingAs($this->admin)->get(route('faturacao.notas.mostrar', $nota->id))
+            ->assertOk()->assertSee('Emitir nota')->assertSee('Devolução parcial');
+
+        $this->actingAs($this->admin)->post(route('faturacao.notas.emitir', $nota->id))
+            ->assertRedirect(route('faturacao.notas.mostrar', $nota->id))
+            ->assertSessionHas('sucesso');
+
+        $nota->refresh();
+        $this->assertSame('emitida', $nota->estado);
+        $this->assertStringStartsWith('NC ', $nota->numero_documento);
+
+        $this->actingAs($this->admin)->get(route('faturacao.notas.mostrar', $nota->id))
+            ->assertOk()->assertSee($nota->numero_documento)->assertDontSee('Emitir nota');
+
+        // A fatura passa a ligar à nota e o recibo já só vê o que resta.
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertOk()->assertSee($nota->numero_documento);
+    }
+
+    public function test_credito_acima_do_faturado_fica_em_rascunho_com_mensagem(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura, ['quantidade' => 3]))->assertRedirect();
+        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
+
+        $this->actingAs($this->admin)->post(route('faturacao.notas.emitir', $nota->id))->assertSessionHas('erro');
+
+        $this->assertSame('rascunho', $nota->fresh()->estado);
+    }
+
+    public function test_erro_de_regra_volta_ao_formulario_com_a_mensagem_e_as_linhas(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $volta = route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $fatura->id]);
+
+        // Linha a 0% sem motivo de isenção: regra de negócio, não de formato.
+        $resposta = $this->actingAs($this->admin)->from($volta)
+            ->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura, ['descricao' => 'Linha-a-reter-XYZ', 'taxa_iva' => 0]));
+
+        $resposta->assertRedirect($volta)->assertSessionHasErrors(['nota']);
+        $this->assertStringContainsString('exige o motivo de isenção', session('errors')->first('nota'));
+        $this->assertSame(0, NotaCreditoDebito::withoutGlobalScopes()->count());
+
+        $this->actingAs($this->admin)->get($volta)->assertOk()->assertSee('Linha-a-reter-XYZ');
+    }
+
+    public function test_validacao_de_formato_motivo_curto(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), [...$this->pedidoCredito($fatura), 'motivo' => 'x'])
+            ->assertSessionHasErrors(['motivo']);
+    }
+
+    public function test_nota_de_debito_sem_fatura_nao_leva_iva(): void
+    {
+        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), [
+            'tipo' => 'debito',
+            'cliente_id' => $this->cliente->id,
+            'motivo' => 'Repasse de despesas',
+            'linhas' => [['descricao' => 'Despesas de deslocação', 'quantidade' => 1, 'preco_unitario' => '250.00']],
+        ])->assertSessionHasNoErrors()->assertRedirect();
+
+        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
+        $this->assertSame('debito', $nota->tipo);
+        $this->assertSame('0.00', $nota->valor_iva);
+        $this->assertSame('250.00', $nota->valor_total);
+        $this->assertNull($nota->fatura_id);
+
+        $this->actingAs($this->admin)->post(route('faturacao.notas.emitir', $nota->id))->assertSessionHas('sucesso');
+        $this->assertStringStartsWith('ND ', $nota->fresh()->numero_documento);
+    }
+
+    public function test_nota_de_debito_com_iva_enviado_no_pedido_nao_o_liquida(): void
+    {
+        // Mesmo que alguém force uma taxa no pedido, a ND sem IVA grava sempre taxa 0.
+        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), [
+            'tipo' => 'debito',
+            'cliente_id' => $this->cliente->id,
+            'motivo' => 'Repasse',
+            'linhas' => [['descricao' => 'Despesa', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14]],
+        ])->assertSessionHasNoErrors();
+
+        $this->assertSame('0.00', NotaCreditoDebito::withoutGlobalScopes()->firstOrFail()->valor_iva);
+    }
+
+    public function test_so_o_administrador_emite(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->actingAs($this->operador)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura))->assertRedirect();
+        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
+
+        $this->actingAs($this->operador)->post(route('faturacao.notas.emitir', $nota->id))->assertForbidden();
+
+        $this->assertSame('rascunho', $nota->fresh()->estado);
+    }
+
+    public function test_rascunho_pode_ser_eliminado_mas_nota_emitida_nao(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura));
+        $rascunho = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
+
+        $this->actingAs($this->admin)->delete(route('faturacao.notas.eliminar', $rascunho->id))
+            ->assertRedirect(route('faturacao.notas.index'));
+        $this->assertSame(0, NotaCreditoDebito::withoutGlobalScopes()->count());
+
+        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura));
+        $emitida = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
+        $this->actingAs($this->admin)->post(route('faturacao.notas.emitir', $emitida->id));
+
+        $this->actingAs($this->admin)->delete(route('faturacao.notas.eliminar', $emitida->id))->assertSessionHas('erro');
+        $this->assertSame(1, NotaCreditoDebito::withoutGlobalScopes()->count());
+    }
+
+    public function test_outra_empresa_nao_ve_nem_usa_documentos_alheios(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura));
+        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
+
+        $outra = $this->criarEmpresa('Outra Loja', '5001636864');
+        $intruso = $this->criarUtilizador($outra, 'admin@outra.ao', true);
+
+        $this->actingAs($intruso)->get(route('faturacao.notas.mostrar', $nota->id))->assertNotFound();
+        $this->actingAs($intruso)->post(route('faturacao.notas.emitir', $nota->id))->assertNotFound();
+        $this->actingAs($intruso)->delete(route('faturacao.notas.eliminar', $nota->id))->assertNotFound();
+        $this->actingAs($intruso)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura))->assertSessionHasErrors(['fatura_id']);
+        $this->actingAs($intruso)->get(route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $fatura->id]))
+            ->assertRedirect(route('faturacao.faturas.index'));
+    }
+
+    public function test_lista_de_notas(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura));
+
+        $this->actingAs($this->admin)->get(route('faturacao.notas.index'))
+            ->assertOk()->assertSee('Devolução parcial')->assertSee('Cliente Notas');
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/RecibosInterfaceTest.php b/Modules/Faturacao/tests/Feature/RecibosInterfaceTest.php
new file mode 100644
index 0000000..da9d044
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/RecibosInterfaceTest.php
@@ -0,0 +1,221 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Models\User;
+use Modules\Core\Support\Servico;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\Recibo;
+use Modules\Faturacao\Services\FaturaService;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Spatie\Permission\Models\Role;
+use Spatie\Permission\PermissionRegistrar;
+use Tests\TestCase;
+
+/** Recibos pela interface: emissão só de Administrador, saldo à vista e erros sem 500. */
+class RecibosInterfaceTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
+
+    protected User $admin;
+
+    protected User $operador;
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
+        $this->empresa = $this->criarEmpresa('Loja Recibos', '5001636863');
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Recibos', 'nif' => null]);
+        $this->admin = $this->criarUtilizador($this->empresa, 'admin@recibos.ao', true);
+        $this->operador = $this->criarUtilizador($this->empresa, 'operador@recibos.ao', false);
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
+    protected function criarUtilizador(Empresa $empresa, string $email, bool $administrador): User
+    {
+        $utilizador = User::create(['empresa_id' => $empresa->id, 'name' => $email, 'email' => $email, 'password' => 'Senha-Forte-123!']);
+
+        if ($administrador) {
+            app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
+            $utilizador->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $empresa->id]));
+        }
+
+        return $utilizador;
+    }
+
+    /** Fatura de 1000 + 14% = 1140. */
+    protected function faturaEmitida(): Fatura
+    {
+        $faturas = app(FaturaService::class);
+
+        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
+        ]));
+    }
+
+    /** @return array<string, mixed> */
+    protected function pedido(Fatura $fatura, string $valor, string $meio = 'transferencia'): array
+    {
+        return ['fatura_id' => $fatura->id, 'valor' => $valor, 'meio_pagamento' => $meio];
+    }
+
+    public function test_formulario_mostra_o_valor_em_divida_como_sugestao(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->admin)->get(route('faturacao.recibos.criar', ['fatura' => $fatura->id]))
+            ->assertOk()
+            ->assertSee('Registar recibo')
+            ->assertSee($fatura->numero_documento)
+            ->assertSee('value="1140.00"', false); // input number: ponto decimal, sem separador de milhares
+    }
+
+    public function test_emite_o_recibo_e_a_fatura_mostra_o_recebido(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '400.00'))
+            ->assertSessionHasNoErrors()
+            ->assertRedirect();
+
+        $recibo = Recibo::withoutGlobalScopes()->firstOrFail();
+        $this->assertSame('emitido', $recibo->estado);
+        $this->assertStringStartsWith('RC ', $recibo->numero_documento);
+        $this->assertSame('400.00', $recibo->valor);
+        $this->assertSame($fatura->id, $recibo->fatura_id);
+        $this->assertSame($this->cliente->id, $recibo->cliente_id);
+
+        $this->actingAs($this->admin)->get(route('faturacao.recibos.mostrar', $recibo->id))
+            ->assertOk()->assertSee($recibo->numero_documento)->assertSee('Transferência bancária');
+
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertOk()->assertSee($recibo->numero_documento)->assertSee('Em dívida')->assertSee('740,00');
+    }
+
+    public function test_valor_acima_do_que_esta_em_divida_volta_com_mensagem(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $volta = route('faturacao.recibos.criar', ['fatura' => $fatura->id]);
+
+        $this->actingAs($this->admin)->from($volta)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '1140.01'))
+            ->assertRedirect($volta)
+            ->assertSessionHasErrors(['recibo']);
+
+        $this->assertStringContainsString('excede', session('errors')->first('recibo'));
+        $this->assertSame(0, Recibo::withoutGlobalScopes()->count());
+    }
+
+    public function test_notas_de_credito_emitidas_reduzem_o_que_se_pode_receber(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $notas = app(NotaCreditoDebitoService::class);
+        $notas->emitir($notas->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', 'Devolução', [
+            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '500.00', 'taxa_iva' => 14],
+        ], $fatura->id)); // crédito de 570: restam 570
+
+        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '570.01'))
+            ->assertSessionHasErrors(['recibo']);
+
+        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '570.00', 'dinheiro'))
+            ->assertSessionHasNoErrors();
+
+        $this->assertSame(1, Recibo::withoutGlobalScopes()->count());
+    }
+
+    public function test_fatura_liquidada_nao_aceita_novo_recibo(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '1140.00'))->assertSessionHasNoErrors();
+
+        $this->actingAs($this->admin)->get(route('faturacao.recibos.criar', ['fatura' => $fatura->id]))
+            ->assertRedirect(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertSessionHas('erro');
+
+        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
+            ->assertOk()->assertDontSee('Registar recibo');
+    }
+
+    public function test_fatura_em_rascunho_nao_tem_recibo(): void
+    {
+        $rascunho = app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Rascunho', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => 14],
+        ]);
+
+        $this->actingAs($this->admin)->get(route('faturacao.recibos.criar', ['fatura' => $rascunho->id]))
+            ->assertRedirect(route('faturacao.faturas.index'))
+            ->assertSessionHas('erro');
+    }
+
+    public function test_meio_de_pagamento_e_valor_sao_validados(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '10.00', 'bitcoin'))
+            ->assertSessionHasErrors(['meio_pagamento']);
+
+        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '0'))
+            ->assertSessionHasErrors(['valor']);
+
+        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, 'abc'))
+            ->assertSessionHasErrors(['valor']);
+    }
+
+    public function test_so_o_administrador_emite_recibos(): void
+    {
+        $fatura = $this->faturaEmitida();
+
+        $this->actingAs($this->operador)->get(route('faturacao.recibos.criar', ['fatura' => $fatura->id]))->assertForbidden();
+        $this->actingAs($this->operador)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '10.00'))->assertForbidden();
+
+        $this->assertSame(0, Recibo::withoutGlobalScopes()->count());
+    }
+
+    public function test_outra_empresa_nao_ve_nem_usa_faturas_alheias(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '100.00'));
+        $recibo = Recibo::withoutGlobalScopes()->firstOrFail();
+
+        $outra = $this->criarEmpresa('Outra Loja', '5001636864');
+        $intruso = $this->criarUtilizador($outra, 'admin@outra.ao', true);
+
+        $this->actingAs($intruso)->get(route('faturacao.recibos.mostrar', $recibo->id))->assertNotFound();
+        $this->actingAs($intruso)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '10.00'))->assertSessionHasErrors(['fatura_id']);
+        $this->actingAs($intruso)->get(route('faturacao.recibos.criar', ['fatura' => $fatura->id]))->assertRedirect(route('faturacao.faturas.index'));
+        $this->assertSame(1, Recibo::withoutGlobalScopes()->count());
+    }
+
+    public function test_lista_de_recibos(): void
+    {
+        $fatura = $this->faturaEmitida();
+        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '100.00', 'multicaixa'));
+
+        $this->actingAs($this->admin)->get(route('faturacao.recibos.index'))
+            ->assertOk()->assertSee('Cliente Recibos')->assertSee('Multicaixa');
+    }
+}
