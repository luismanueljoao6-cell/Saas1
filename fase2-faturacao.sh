#!/usr/bin/env bash
# =============================================================================
# fase2-faturacao.sh — correções que faltavam da auditoria técnica do módulo Faturação
#
# Aplica sobre o teu `main` (commit 64d9e67 ou posterior) um patch já testado:
#   - nota de débito sem IVA (Decreto Presidencial 71/25) e envio à AGT como "não sujeita"
#   - isenções de IVA com motivo (taxExemptionCode) — bloqueadas na AGT até confirmares a tabela
#   - limite das notas de crédito por taxa (E41/E42) e preço/quantidade/taxa obrigatórios
#   - chaves: assinar sem empresa já não usa a chave genérica; comando faturacao:verificar-chaves
#   - correção de faturas rejeitadas pela AGT (documentStatus C) — comando faturacao:agt-corrigir
#   - auditoria da cadeia por empresa (+ --agt); SAF-T com TaxTable/DocumentTotals, XSD e versões
#   - documentação (chaves, homologação AGT, matriz de requisitos) e testes novos
#
# Fluxo: pré-verificações -> mede os testes ANTES -> aplica -> sintaxe -> Pint
#        -> testes (módulo + suite completa; falhas que já existiam não contam)
#        -> migrate -> commit -> push para o main.
#        Se algo falhar antes do commit, TUDO é revertido (e a tua base local não é tocada).
#
# Uso:   bash fase2-faturacao.sh [--sem-push] [--sem-commit] [--so-faturacao] [--manter-em-falha]
#   --sem-push         faz o commit mas não envia para o GitHub
#   --sem-commit       aplica e testa, deixa as alterações por commitar (implica --sem-push)
#   --so-faturacao     só corre os testes do módulo Faturação (mais rápido)
#   --manter-em-falha  em caso de falha, não reverte (para inspecionares)
# =============================================================================
set -Eeuo pipefail

BASE_TESTADA="64d9e67"
PATCH_SHA256="59ba600ef5e39994975d26c64445ed2493b5ab8ef2f90fda4c0672fda88134e2"

SEM_PUSH=0; SEM_COMMIT=0; SO_FATURACAO=0; MANTER=0
APLICADO=0; COMMITADO=0
TMP=""

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

# Qualquer saída com erro depois de aplicar o patch (e antes do commit) reverte tudo.
reverter() {
  if [ "$APLICADO" = 1 ] && [ "$COMMITADO" = 0 ]; then
    APLICADO=0
    if [ "$MANTER" = 1 ]; then
      aviso "Alterações mantidas (--manter-em-falha). Para desfazer: git checkout -- . && git clean -fd -- Modules/Faturacao"
      return 0
    fi
    git reset -q -- . 2>/dev/null || true
    git checkout -q -- . 2>/dev/null || true
    git clean -fdq -- Modules/Faturacao 2>/dev/null || true
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

# ---------------------------------------------------------------- pré-verificações
passo "Pré-verificações"

[ -f "$0" ] || { erro "Corre este ficheiro com: bash fase2-faturacao.sh (não por pipe)."; exit 1; }
cd "$(git rev-parse --show-toplevel 2>/dev/null)" || { erro "Corre isto dentro do repositório Saas1."; exit 1; }
[ -d Modules/Faturacao ] || { erro "Não encontro Modules/Faturacao: estás no repositório certo?"; exit 1; }

for cmd in git php python3 awk sed; do
  command -v "$cmd" >/dev/null 2>&1 || { erro "Falta o comando '$cmd'."; exit 1; }
done

if [ -f Modules/Faturacao/app/Services/AgtCorrecaoService.php ]; then
  ok "Esta fase já está aplicada neste repositório. Nada a fazer."
  exit 0
fi

if [ -n "$(git status --porcelain)" ]; then
  erro "Tens alterações por commitar. Faz commit (ou git stash) e volta a correr."
  git status --short >&2
  exit 1
fi

[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || git checkout -q main
git pull --ff-only origin main
ok "main atualizado: $(git log --oneline -1)"

[ -f Modules/Faturacao/app/Support/ValidacaoLinhas.php ] || {
  erro "Falta a correção anterior (ValidacaoLinhas): este patch assume o main a partir de ${BASE_TESTADA}."
  exit 1
}

TMP="$(mktemp -d)"
LOG_MODULO="${TMPDIR:-/tmp}/fase2-testes-faturacao.log"
LOG_TODOS="${TMPDIR:-/tmp}/fase2-testes-completos.log"

sed '1,/^__PATCH_FASE2__$/d' "$0" > "$TMP/fase2.patch"
[ -s "$TMP/fase2.patch" ] || { erro "O patch não está no ficheiro (descarrega o script de novo)."; exit 1; }
[ "$(hash_de "$TMP/fase2.patch")" = "$PATCH_SHA256" ] || { erro "O SHA-256 do patch não confere: o ficheiro foi truncado ou alterado. Descarrega-o de novo."; exit 1; }

if ! git apply --check --whitespace=nowarn "$TMP/fase2.patch" 2>"$TMP/check.err"; then
  erro "O patch não encaixa no teu main (mudou desde ${BASE_TESTADA} nos ficheiros que ele toca):"
  cat "$TMP/check.err" >&2
  exit 1
fi
ok "O patch encaixa; nada foi alterado ainda."

export XDEBUG_MODE=off

# ---------------------------------------------------------------- estado dos testes antes
BASE_CONHECIDA=0
: > "$TMP/antes.falhas"
if [ "$SO_FATURACAO" = 0 ]; then
  passo "A medir os testes ANTES de alterar nada (as falhas que já existem não contam)"
  php artisan test --log-junit="$TMP/antes.xml" >"$TMP/antes.log" 2>&1 || true
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
git apply --whitespace=nowarn "$TMP/fase2.patch"
APLICADO=1
ok "$(git apply --numstat "$TMP/fase2.patch" | wc -l | tr -d ' ') ficheiros alterados/criados"

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
php -d memory_limit=-1 artisan test Modules/Faturacao 2>&1 | tee "$LOG_MODULO" | tail -n 8
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

# ---------------------------------------------------------------- migração da base local
# (depois dos testes: se algo falhar antes, a tua base de desenvolvimento não é tocada)
passo "Migração (acrescenta motivo_isencao e rejeitado_documento_no)"
php artisan migrate --force || aviso "Não consegui migrar a base de dados local — corre 'php artisan migrate' quando a base estiver acessível. Os testes usam a sua própria base."

# ---------------------------------------------------------------- commit e push
if [ "$SEM_COMMIT" = 1 ]; then
  passo "Pronto (sem commit)"
  ok "Alterações aplicadas e testadas, por commitar. Revê com: git status"
  exit 0
fi

passo "Commit"
git add -A
git commit -q \
  -m "fix(faturacao): fecha as lacunas restantes da auditoria (ND sem IVA, isenções, limite por taxa, chaves, correção de rejeitados, SAF-T)" \
  -m "Nota de débito sem IVA e enviada como não sujeita; linhas isentas com motivo (tabela por confirmar, bloqueada com a AGT ativa); limite das notas de crédito por taxa; preço/quantidade/taxa obrigatórios; assinar sem empresa já não usa a chave genérica; faturacao:verificar-chaves; faturacao:agt-corrigir (documentStatus C); auditoria da cadeia por empresa e com --agt; SAF-T com TaxTable/DocumentTotals, validação XSD e ficheiros versionados; documentação e testes."
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
Feito. O que continua em aberto (não se resolve com código):

 1. Isenções / nota de débito com a AGT ativa: compara a tabela de motivos
    (Modules/Faturacao/config/config.php) com o Anexo 6.4 OFICIAL, corrige-a e
    só então põe no .env  FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS=true  e
    FATURACAO_AGT_ND_CODIGO_NS=<código NS>. Para aceitar isenções: FATURACAO_TAXAS_IVA_PERMITIDAS=14,0
 2. SAF-T: continua provisório. Põe o XSD oficial em FATURACAO_SAFT_XSD.
 3. Corre:  php artisan faturacao:verificar-chaves
 4. Homologação real com a AGT: Modules/Faturacao/docs/HOMOLOGACAO_AGT.md
 5. Conformidade: Modules/Faturacao/docs/MATRIZ_REQUISITOS_FISCAIS.md
─────────────────────────────────────────────────────────────────────────────
FIM
exit 0
__PATCH_FASE2__
diff --git a/.env.example b/.env.example
index ff394bf..8734032 100644
--- a/.env.example
+++ b/.env.example
@@ -76,10 +76,17 @@ DB_QUEUE_RETRY_AFTER=660
 # FATURACAO_CHAVES_DIR=
 FATURACAO_CHAVE_VERSAO=1
 FATURACAO_TAXA_IVA_GERAL=14
-# Taxas aceites na emissão (confirma na tabela atual da AGT)
+# Taxas aceites na emissão (confirma na tabela atual da AGT). Para aceitar isenções: 14,0
 FATURACAO_TAXAS_IVA_PERMITIDAS=14
 # Em produção, o SAF-T é bloqueado enquanto isto for 0
 FATURACAO_SAFT_NUMERO_CERTIFICADO=0
+# XSD OFICIAL do SAF-T (AO): sem ele os ficheiros ficam "não validados" e, em produção, não se descarregam
+# FATURACAO_SAFT_XSD=
+# Só true depois de comparares a tabela faturacao.motivos_isencao com o Anexo 6.4 oficial.
+# Enquanto for false, com a AGT ativa as isenções e as notas de débito não são aceites/enviadas.
+FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS=false
+# Código de não sujeição (taxExemptionCode, tipo NS da tabela de motivos) das notas de débito
+# FATURACAO_AGT_ND_CODIGO_NS=
 
 # --- Segurança / proxies ---
 # IPs do teu proxy/load balancer, separados por vírgula. Vazio = não confiar em ninguém.
diff --git a/Modules/Faturacao/README.md b/Modules/Faturacao/README.md
index 16e17d8..cb5ea36 100644
--- a/Modules/Faturacao/README.md
+++ b/Modules/Faturacao/README.md
@@ -141,7 +141,8 @@ ## O que fica para depois (por desenho)
   services existem e estão testados (`NotaCreditoDebitoService`), falta o
   controller/views equivalentes aos de Fatura.
 - **Validação do SAF-T contra o XSD oficial** — o `SafTExportService`
-  gera uma estrutura plausível, não validada campo a campo.
+  gera uma estrutura provisória; passa a "validada" quando o XSD oficial
+  (`FATURACAO_SAFT_XSD`) a aceitar (ver "Correções da auditoria").
 - **Faturas de outros módulos** (ex.: a subscrição SaaS da própria
   plataforma) não passam por aqui — este módulo fatura AS EMPRESAS
   clientes da plataforma aos SEUS PRÓPRIOS clientes, não a relação entre
@@ -222,7 +223,9 @@ ## Assinatura JWS (AGT) — regime atual
   não faz parte do objecto assinado.
 - **O IVA é arredondado por excesso** ao cêntimo (regra do campo `taxContribution`).
 - Chaves por empresa: `storage/app/faturacao/chaves/{NIF}.pem` (emitidas pela AGT
-  no Portal do Contribuinte); sem esse ficheiro usa-se a chave por omissão.
+  no Portal do Contribuinte). Sem esse ficheiro a emissão **falha**: a chave por omissão só
+  é aceite em desenvolvimento/testes (`APP_ENV` local/testing e AGT desligada). Ver
+  `docs/CHAVES.md` e `php artisan faturacao:verificar-chaves`.
 
 Ainda **não** feito (fases seguintes): pedido de séries à AGT, envio
 `registarFactura`, QR Code, tipos de operação, códigos de isenção e SAF-T(AO).
@@ -255,10 +258,11 @@ ## Envio à AGT (Fase 2b)
 no documento, por isso é igual ao que foi assinado.
 
 Limitações atuais (os documentos nestes casos ficam em `erro`, sem bloquear os outros):
-IVA 0% / isenções (falta o código do Anexo 6.4), recibos sem fatura (adiantamentos),
-documentos em divisa, notas de crédito sem fatura original. `operationType` é `SG` para
-todas as linhas e só a taxa de 14% (`NOR`) está mapeada em `agt.codigos_taxa`.
-Um documento `invalido`/`rejeitado` só se corrige com um documento novo (regra E46) — fluxo ainda por fazer.
+isenções e notas de débito enquanto a tabela de motivos não estiver confirmada (ver
+"Correções da auditoria"), documentos em divisa, recibos sem fatura (recusados logo na
+emissão com a AGT ativa). `operationType` é `SG` para todas as linhas e só a taxa de 14%
+(`NOR`) está mapeada em `agt.codigos_taxa`. Um documento `invalido`/`rejeitado` só se
+corrige com um documento novo (regra E46): `php artisan faturacao:agt-corrigir <id>` (só faturas).
 
 
 ## Cliente congelado e QR Code (Fase 3a)
@@ -276,3 +280,39 @@ ## Cliente congelado e QR Code (Fase 3a)
 O esquema SAF-T(AO) público (SAFTAO1.01_01, 2020) limita esse campo a 172 caracteres. Falta a
 especificação atual da AGT para o SAF-T com faturação eletrónica; até a haver, não uses o SAF-T
 exportado para submissões.
+
+
+## Correções da auditoria técnica (Fase 2)
+
+O que esta fase fechou, e o que **continua em aberto**. Cada ponto tem teste automático.
+
+- **Nota de débito sem IVA** (Decreto Presidencial 71/25, art. 3.º, al. m): uma ND não liquida
+  imposto — taxa omitida vale 0, qualquer taxa acima de 0 é recusada (e a emissão volta a
+  verificar). Para a AGT segue com `taxType` NS + `taxExemptionCode`
+  (`FATURACAO_AGT_ND_CODIGO_NS`, tipo NS da tabela de motivos).
+- **Isenções (IVA 0%)**: cada linha a 0% exige `motivo_isencao` (tabela `faturacao.motivos_isencao`);
+  segue como IVA/`ISE` ou NS conforme o tipo do motivo, sempre com `taxExemptionCode`. Para ativar:
+  `FATURACAO_TAXAS_IVA_PERMITIDAS=14,0`. **A tabela é uma transcrição de terceiros, não o Anexo 6.4
+  oficial**: com a AGT ativa, isenções e notas de débito só funcionam depois de a comparares com
+  o anexo e definires `FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS=true`.
+- **Limite das notas de crédito**: além do total, o valor creditado sem IVA não excede o da fatura,
+  taxa a taxa (a AGT valida o mesmo, E41/E42).
+- **Preço, quantidade e taxa são obrigatórios**: um valor em falta ou vazio nunca vira 0 em silêncio.
+- **Chaves**: assinar sem empresa já não cai na chave genérica fora de desenvolvimento;
+  `php artisan faturacao:verificar-chaves` e `docs/CHAVES.md` (verificação e rotação).
+- **Correção de faturas rejeitadas**: `php artisan faturacao:agt-corrigir <id da submissão>` cria o rascunho
+  do documento novo; ao emitir segue com `documentStatus` C + `rejectedDocumentNo`. Só faturas;
+  NC/ND/recibos rejeitados exigem tratamento manual.
+- **Auditoria da cadeia**: `faturacao:verificar-cadeia` usa a chave pública de cada empresa, confere o
+  número do documento e o intervalo autorizado pela AGT, e com `--agt` a correspondência com a AGT.
+- **SAF-T**: a estrutura ganhou `TaxTable` e `DocumentTotals`, mas **continua provisória** (sem namespace,
+  sem recibos, `Hash` > 172 caracteres). Cada geração é uma versão nova com SHA-256 e estado
+  (`nao_validado` / `valido_xsd` / `invalido_xsd`); com o XSD oficial em `FATURACAO_SAFT_XSD` o ficheiro é
+  validado, e em produção só se descarrega o que passou. Sem XSD nunca se finge validação.
+
+**Continua em aberto** (não se resolve só com código): conformidade fiscal integral e
+certificação do software; XSD oficial e estrutura SAF-T completa; confirmação da tabela de motivos
+de isenção (Anexo 6.4); homologação real com a AGT (`docs/HOMOLOGACAO_AGT.md`); descontos, divisas,
+retenções e os restantes tipos de documento (`docs/MATRIZ_REQUISITOS_FISCAIS.md`); interface para
+notas de crédito/débito e recibos, e edição/eliminação de rascunhos.
+
diff --git a/Modules/Faturacao/app/Console/CorrigirDocumentoAgtCommand.php b/Modules/Faturacao/app/Console/CorrigirDocumentoAgtCommand.php
new file mode 100644
index 0000000..25a7945
--- /dev/null
+++ b/Modules/Faturacao/app/Console/CorrigirDocumentoAgtCommand.php
@@ -0,0 +1,45 @@
+<?php
+
+namespace Modules\Faturacao\Console;
+
+use Illuminate\Console\Command;
+use Modules\Faturacao\Models\AgtSubmissao;
+use Modules\Faturacao\Services\AgtCorrecaoService;
+
+/**
+ * Cria o rascunho da fatura que corrige um documento inválido ou rejeitado pela AGT
+ * (documento novo, documentStatus "C" + rejectedDocumentNo). Não emite nem envia nada.
+ */
+class CorrigirDocumentoAgtCommand extends Command
+{
+    protected $signature = 'faturacao:agt-corrigir {submissao : ID da submissão (tabela agt_submissoes) inválida ou rejeitada}';
+
+    protected $description = 'Cria o rascunho da fatura que corrige um documento inválido ou rejeitado pela AGT (documentStatus C)';
+
+    public function handle(AgtCorrecaoService $correcoes): int
+    {
+        $submissao = AgtSubmissao::query()->find((int) $this->argument('submissao'));
+
+        if (! $submissao) {
+            $this->error('Submissão não encontrada.');
+
+            return self::FAILURE;
+        }
+
+        foreach ((array) $submissao->erros as $erro) {
+            $this->line('AGT: '.(is_array($erro) ? trim(($erro['codigo'] ?? '').' '.($erro['descricao'] ?? '')) : (string) $erro));
+        }
+
+        try {
+            $rascunho = $correcoes->criarRascunhoDeCorrecao($submissao);
+        } catch (\DomainException $e) {
+            $this->error($e->getMessage());
+
+            return self::FAILURE;
+        }
+
+        $this->info("Rascunho #{$rascunho->id} para corrigir {$submissao->documento_no}. Revê-o em /faturacao/faturas/{$rascunho->id} e emite-o: segue para a AGT com documentStatus C.");
+
+        return self::SUCCESS;
+    }
+}
diff --git a/Modules/Faturacao/app/Console/VerificarCadeiaFiscalCommand.php b/Modules/Faturacao/app/Console/VerificarCadeiaFiscalCommand.php
index 46b4ca7..7a431a8 100644
--- a/Modules/Faturacao/app/Console/VerificarCadeiaFiscalCommand.php
+++ b/Modules/Faturacao/app/Console/VerificarCadeiaFiscalCommand.php
@@ -4,41 +4,78 @@
 
 use Illuminate\Console\Command;
 use Modules\Core\Services\TenantManager;
+use Modules\Faturacao\Models\AgtSubmissao;
 use Modules\Faturacao\Models\Fatura;
 use Modules\Faturacao\Models\NotaCreditoDebito;
 use Modules\Faturacao\Models\Recibo;
+use Modules\Faturacao\Models\Serie;
 use Modules\Faturacao\Services\AssinaturaFiscalService;
 use Throwable;
 
 /**
- * Auditoria independente: para cada série confirma numeração sem saltos,
- * hash_anterior == hash do documento anterior e assinatura RSA válida.
- * Documentos assinados com uma versão antiga da chave precisam da chave
- * pública dessa versão (--chave-publica).
+ * Auditoria independente: para cada série confirma numeração sem saltos (e dentro do
+ * intervalo autorizado pela AGT), número do documento coerente com o nº sequencial,
+ * hash_anterior == hash do documento anterior, assinatura RSA válida e conteúdo
+ * assinado igual ao persistido. Com --agt confirma também a correspondência com a AGT.
+ *
+ * Chave pública, por ordem: --chave-publica (todas as empresas); a derivada da chave
+ * privada da própria empresa ({NIF}.pem, só neste servidor); o ficheiro por omissão
+ * storage/app/faturacao/chave-publica.pem. Documentos assinados com uma versão antiga
+ * da chave precisam da chave pública dessa versão (--chave-publica).
  */
 class VerificarCadeiaFiscalCommand extends Command
 {
     protected $signature = 'faturacao:verificar-cadeia
         {--empresa= : ID da empresa (por omissão, todas)}
-        {--chave-publica= : Caminho da chave pública PEM}';
+        {--chave-publica= : Caminho da chave pública PEM (por omissão, a de cada empresa)}
+        {--agt : Confirma também a correspondência com a AGT (só o estado "válido" é confirmação fiscal)}';
 
     protected $description = 'Verifica numeração, cadeia de hashes e assinaturas dos documentos fiscais emitidos';
 
     public function handle(TenantManager $tenantManager, AssinaturaFiscalService $assinatura): int
     {
-        $chavePublica = $this->option('chave-publica') ?: storage_path('app/faturacao/chave-publica.pem');
+        $manual = $this->option('chave-publica');
 
-        if (! is_file($chavePublica)) {
-            $this->error("Chave pública não encontrada em {$chavePublica}. Usa --chave-publica=.");
+        if ($manual && ! is_file($manual)) {
+            $this->error("Chave pública não encontrada em {$manual}. Usa --chave-publica=.");
 
             return self::FAILURE;
         }
 
+        $padrao = storage_path('app/faturacao/chave-publica.pem');
+        $comAgt = (bool) $this->option('agt');
         $empresaId = $this->option('empresa') !== null ? (int) $this->option('empresa') : null;
+
         $problemas = [];
+        $avisos = [];
         $total = 0;
+        /** @var array<int, string|false> $chaves PEM da chave pública por empresa (false: não há) */
+        $chaves = [];
+        /** @var array<int, Serie|null> $series */
+        $series = [];
+
+        $chavePublicaDe = function (int $empresa) use (&$chaves, $manual, $padrao, $assinatura, &$problemas): string|false {
+            if (array_key_exists($empresa, $chaves)) {
+                return $chaves[$empresa];
+            }
+
+            try {
+                $pem = $manual
+                    ? (string) file_get_contents($manual)
+                    : ($assinatura->chavePublicaDaEmpresa($empresa) ?? (is_file($padrao) ? (string) file_get_contents($padrao) : false));
+            } catch (Throwable $e) {
+                $pem = false;
+            }
+
+            if ($pem === false || $pem === '') {
+                $problemas[] = ["empresa #{$empresa}", '-', '-', 'sem chave pública para verificar as assinaturas (chave da empresa em falta; usa --chave-publica=)'];
+                $pem = false;
+            }
+
+            return $chaves[$empresa] = $pem;
+        };
 
-        $tenantManager->semTenant(function () use ($empresaId, $assinatura, $chavePublica, &$problemas, &$total) {
+        $tenantManager->semTenant(function () use ($empresaId, $assinatura, $comAgt, $chavePublicaDe, &$series, &$problemas, &$avisos, &$total) {
             foreach ([Fatura::class, NotaCreditoDebito::class, Recibo::class] as $modelo) {
                 $consulta = $modelo::query()
                     ->whereNotNull('numero_sequencial')
@@ -62,20 +99,49 @@ public function handle(TenantManager $tenantManager, AssinaturaFiscalService $as
                         $hashAnterior = null;
                     }
 
+                    $serie = $series[$serieAtual] ??= Serie::query()->find($serieAtual);
+
                     if ($numero !== $esperado) {
                         $problemas[] = [$nome, $serieAtual, $numero, "salto de numeração (esperado {$esperado})"];
                     }
 
+                    if (! str_ends_with((string) $doc->numero_documento, '/'.$numero) && ! str_ends_with((string) $doc->numero_documento, (string) $numero)) {
+                        $problemas[] = [$nome, $serieAtual, $numero, 'número do documento não corresponde ao nº sequencial'];
+                    }
+
+                    if ($serie?->agt_codigo !== null
+                        && $serie->ultimo_numero_autorizado !== null
+                        && $numero > (int) $serie->ultimo_numero_autorizado) {
+                        $problemas[] = [$nome, $serieAtual, $numero, "fora do intervalo autorizado pela AGT (até {$serie->ultimo_numero_autorizado})"];
+                    }
+
                     if (($doc->hash_anterior ?? null) !== $hashAnterior) {
                         $problemas[] = [$nome, $serieAtual, $numero, 'hash_anterior não coincide com o documento anterior'];
                     }
 
-                    try {
-                        if (! $assinatura->verificar($doc->construirStringParaAssinar(), $doc->hash, $chavePublica)) {
-                            $problemas[] = [$nome, $serieAtual, $numero, 'assinatura RSA inválida'];
+                    $pem = $chavePublicaDe((int) $doc->empresa_id);
+
+                    if ($pem !== false) {
+                        try {
+                            if (! $assinatura->verificarComPem($doc->construirStringParaAssinar(), $doc->hash, $pem)) {
+                                $problemas[] = [$nome, $serieAtual, $numero, 'assinatura RSA inválida'];
+                            }
+                        } catch (Throwable $e) {
+                            $problemas[] = [$nome, $serieAtual, $numero, 'erro ao verificar: '.$e->getMessage()];
+                        }
+                    }
+
+                    if ($comAgt) {
+                        $submissao = AgtSubmissao::query()
+                            ->where('documento_type', $modelo)
+                            ->where('documento_id', $doc->getKey())
+                            ->first();
+
+                        if (! $submissao) {
+                            $problemas[] = [$nome, $serieAtual, $numero, 'sem registo de submissão à AGT'];
+                        } elseif (! $submissao->confirmadoFiscalmente()) {
+                            $avisos[] = [$nome, $serieAtual, $numero, 'AGT: '.$submissao->rotulo()];
                         }
-                    } catch (Throwable $e) {
-                        $problemas[] = [$nome, $serieAtual, $numero, 'erro ao verificar: '.$e->getMessage()];
                     }
 
                     $esperado = $numero + 1;
@@ -84,6 +150,10 @@ public function handle(TenantManager $tenantManager, AssinaturaFiscalService $as
             }
         });
 
+        if ($avisos !== []) {
+            $this->table(['Tipo', 'Série', 'Nº', 'Aviso'], $avisos);
+        }
+
         if ($problemas !== []) {
             $this->table(['Tipo', 'Série', 'Nº', 'Problema'], $problemas);
             $this->error(count($problemas)." problema(s) em {$total} documento(s).");
diff --git a/Modules/Faturacao/app/Console/VerificarChavesCommand.php b/Modules/Faturacao/app/Console/VerificarChavesCommand.php
new file mode 100644
index 0000000..eaf3dca
--- /dev/null
+++ b/Modules/Faturacao/app/Console/VerificarChavesCommand.php
@@ -0,0 +1,179 @@
+<?php
+
+namespace Modules\Faturacao\Console;
+
+use Illuminate\Console\Command;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Services\AssinaturaFiscalService;
+
+/**
+ * Auditoria das chaves de assinatura: cada empresa assina com a SUA chave
+ * ({chaves_dir}/{NIF}.pem), RSA com o mínimo de bits, sem partilhar chaves entre
+ * empresas, com a chave do software e a chave genérica à parte, e com permissões
+ * fechadas. Procedimento de rotação: ver docs/CHAVES.md do módulo.
+ */
+class VerificarChavesCommand extends Command
+{
+    protected $signature = 'faturacao:verificar-chaves
+        {--empresa= : ID da empresa (por omissão, todas)}';
+
+    protected $description = 'Verifica as chaves de assinatura fiscal: uma por empresa (NIF), RSA com o mínimo de bits, permissões e sem chaves partilhadas';
+
+    public function handle(AssinaturaFiscalService $assinatura): int
+    {
+        // Com a AGT ativa ou em produção, uma chave em falta é um problema; em desenvolvimento, só um aviso.
+        $exigir = (bool) config('faturacao.agt.ativo') || app()->isProduction();
+
+        /** @var array<int, array{0: string, 1: string}> $problemas */
+        $problemas = [];
+        /** @var array<int, array{0: string, 1: string}> $avisos */
+        $avisos = [];
+        /** @var array<string, string> $utilizadores impressão da chave pública => quem a usa */
+        $utilizadores = [];
+        $verificadas = 0;
+
+        $empresaId = $this->option('empresa') !== null ? (int) $this->option('empresa') : null;
+        $empresas = Empresa::query()
+            ->when($empresaId, fn ($q) => $q->whereKey($empresaId))
+            ->orderBy('id')
+            ->get(['id', 'nif']);
+
+        foreach ($empresas as $empresa) {
+            $rotulo = "empresa #{$empresa->id}";
+
+            if (trim((string) $empresa->nif) === '') {
+                $problemas[] = [$rotulo, 'sem NIF: não é possível localizar a chave'];
+
+                continue;
+            }
+
+            $caminho = $assinatura->caminhoDaChaveDaEmpresa((int) $empresa->id);
+
+            if (! is_file($caminho)) {
+                $mensagem = 'chave em falta: '.$caminho;
+
+                if ($exigir) {
+                    $problemas[] = [$rotulo, $mensagem];
+                } else {
+                    $avisos[] = [$rotulo, $mensagem];
+                }
+
+                continue;
+            }
+
+            [$impressao, $erro] = $this->analisar($caminho);
+
+            if ($erro !== null) {
+                $problemas[] = [$rotulo, "chave inválida: {$erro}"];
+
+                continue;
+            }
+
+            $verificadas++;
+
+            if (isset($utilizadores[$impressao])) {
+                $problemas[] = [$rotulo, "usa a mesma chave que {$utilizadores[$impressao]}"];
+            } else {
+                $utilizadores[$impressao] = $rotulo;
+            }
+
+            $permissoes = DIRECTORY_SEPARATOR === '/' ? (fileperms($caminho) & 0777) : 0;
+
+            if (($permissoes & 0077) !== 0) {
+                $avisos[] = [$rotulo, sprintf('permissões %o demasiado abertas no ficheiro da chave (usa chmod 600)', $permissoes)];
+            }
+        }
+
+        $this->verificarChaveGenerica($utilizadores, $exigir, $problemas, $avisos);
+        $this->verificarChaveDoSoftware($utilizadores, $problemas);
+
+        if ($avisos !== []) {
+            $this->table(['Âmbito', 'Aviso'], $avisos);
+        }
+
+        if ($problemas !== []) {
+            $this->table(['Âmbito', 'Problema'], $problemas);
+            $this->error(count($problemas).' problema(s) nas chaves de assinatura (ver docs/CHAVES.md do módulo Faturação).');
+
+            return self::FAILURE;
+        }
+
+        $this->info("{$verificadas} chave(s) de empresa verificadas: RSA, sem partilha entre empresas.");
+
+        return self::SUCCESS;
+    }
+
+    /**
+     * @param  array<string, string>  $utilizadores
+     * @param  array<int, array{0: string, 1: string}>  $problemas
+     * @param  array<int, array{0: string, 1: string}>  $avisos
+     */
+    protected function verificarChaveGenerica(array &$utilizadores, bool $exigir, array &$problemas, array &$avisos): void
+    {
+        $generica = (string) config('faturacao.chave_privada_path');
+
+        if ($generica === '' || ! is_file($generica)) {
+            return;
+        }
+
+        [$impressao] = $this->analisar($generica);
+
+        if ($impressao !== null && isset($utilizadores[$impressao])) {
+            $problemas[] = ['chave genérica', "é igual à chave de {$utilizadores[$impressao]}: cada empresa tem de ter a sua própria chave"];
+        }
+
+        if ($exigir) {
+            $avisos[] = ['chave genérica', 'o ficheiro '.basename($generica).' existe no servidor; com a AGT ativa ou em produção não é usado — remove-o'];
+        }
+    }
+
+    /**
+     * @param  array<string, string>  $utilizadores
+     * @param  array<int, array{0: string, 1: string}>  $problemas
+     */
+    protected function verificarChaveDoSoftware(array $utilizadores, array &$problemas): void
+    {
+        if (! config('faturacao.agt.ativo')) {
+            return;
+        }
+
+        $caminho = (string) config('faturacao.agt.software.chave_privada_path');
+
+        if ($caminho === '' || ! is_file($caminho)) {
+            $problemas[] = ['chave do software', 'chave privada do software em falta (FATURACAO_AGT_CHAVE_SOFTWARE)'];
+
+            return;
+        }
+
+        [$impressao, $erro] = $this->analisar($caminho);
+
+        if ($erro !== null) {
+            $problemas[] = ['chave do software', "chave inválida: {$erro}"];
+        } elseif (isset($utilizadores[$impressao])) {
+            $problemas[] = ['chave do software', "é igual à chave de {$utilizadores[$impressao]}: o software e os emissores têm chaves distintas"];
+        }
+    }
+
+    /** @return array{0: ?string, 1: ?string} [impressão da chave pública, erro] */
+    protected function analisar(string $caminho): array
+    {
+        $chave = openssl_pkey_get_private((string) file_get_contents($caminho));
+
+        if (! $chave) {
+            return [null, 'não é uma chave privada PEM válida'];
+        }
+
+        $detalhes = openssl_pkey_get_details($chave) ?: [];
+        $minimo = (int) config('faturacao.chave_minima_bits', 2048);
+
+        if (($detalhes['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
+            return [null, 'não é uma chave RSA'];
+        }
+
+        if ((int) ($detalhes['bits'] ?? 0) < $minimo) {
+            return [null, 'tem '.(int) ($detalhes['bits'] ?? 0)." bits (mínimo {$minimo})"];
+        }
+
+        return [hash('sha256', (string) ($detalhes['key'] ?? '')), null];
+    }
+}
diff --git a/Modules/Faturacao/app/Http/Controllers/FaturaController.php b/Modules/Faturacao/app/Http/Controllers/FaturaController.php
index b5c1167..cd19bf1 100644
--- a/Modules/Faturacao/app/Http/Controllers/FaturaController.php
+++ b/Modules/Faturacao/app/Http/Controllers/FaturaController.php
@@ -35,6 +35,8 @@ public function criar(): View
             'clientes' => Cliente::orderBy('nome')->get(),
             'produtos' => Produto::ativos()->orderBy('nome')->get(),
             'taxaIvaGeral' => (float) config('faturacao.taxa_iva_geral'),
+            'motivosIsencao' => collect((array) config('faturacao.motivos_isencao', []))->map(fn (array $m, string $codigo) => "{$codigo} — {$m['descricao']}")->all(),
+            'aceitaIsencao' => in_array(0.0, array_map('floatval', (array) config('faturacao.taxas_iva_permitidas', [])), true),
         ]);
     }
 
@@ -47,12 +49,17 @@ public function guardar(GuardarFaturaRequest $request): RedirectResponse
 
         $cliente = Cliente::findOrFail($request->validated('cliente_id'));
 
-        $fatura = $this->faturaService->criarRascunho(
-            $empresa,
-            $cliente,
-            $request->validated('linhas'),
-            $request->validated('observacoes'),
-        );
+        try {
+            $fatura = $this->faturaService->criarRascunho(
+                $empresa,
+                $cliente,
+                $request->validated('linhas'),
+                $request->validated('observacoes'),
+            );
+        } catch (\DomainException|\InvalidArgumentException $e) {
+            // Regras de negócio (ex.: linha isenta sem motivo): mensagens seguras para o utilizador.
+            return back()->withInput()->withErrors(['linhas' => $e->getMessage()]);
+        }
 
         return redirect()->route('faturacao.faturas.mostrar', $fatura)
             ->with('sucesso', 'Rascunho criado. Revê os valores antes de emitir.');
diff --git a/Modules/Faturacao/app/Http/Controllers/SafTExportController.php b/Modules/Faturacao/app/Http/Controllers/SafTExportController.php
index 58552fc..f59e7dd 100644
--- a/Modules/Faturacao/app/Http/Controllers/SafTExportController.php
+++ b/Modules/Faturacao/app/Http/Controllers/SafTExportController.php
@@ -28,11 +28,21 @@ public function criar(Request $request): View
         $disco = Storage::disk('local');
 
         $ficheiros = collect($disco->files("faturacao/saft/{$empresaId}"))
-            ->map(fn (string $c) => [
-                'nome' => basename($c),
-                'tamanho' => $disco->size($c),
-                'modificado_em' => Carbon::createFromTimestamp($disco->lastModified($c)),
-            ])
+            ->filter(fn (string $c) => str_ends_with($c, '.xml'))
+            ->map(function (string $c) use ($disco) {
+                $meta = $this->metadados($c);
+
+                return [
+                    'nome' => basename($c),
+                    'tamanho' => $disco->size($c),
+                    'modificado_em' => Carbon::createFromTimestamp($disco->lastModified($c)),
+                    'estado' => $meta['estado'],
+                    'rotulo' => SafTExportService::ROTULOS[$meta['estado']] ?? $meta['estado'],
+                    'sha256' => $meta['sha256'],
+                    'erros' => $meta['erros_xsd'],
+                    'descarregavel' => $this->descarregavel($meta['estado']),
+                ];
+            })
             ->sortByDesc('modificado_em')
             ->values();
 
@@ -73,11 +83,38 @@ public function despachar(Request $request): RedirectResponse
 
     public function descarregar(Request $request, string $ficheiro): StreamedResponse
     {
-        abort_unless(preg_match('/^SAFT_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.xml$/', $ficheiro) === 1, 404);
+        abort_unless(preg_match('/^SAFT_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}(?:_\d{14}(?:-\d+)?)?\.xml$/', $ficheiro) === 1, 404);
 
         $caminho = "faturacao/saft/{$this->empresaId($request)}/{$ficheiro}";
         abort_unless(Storage::disk('local')->exists($caminho), 404);
 
+        // Em produção só se entrega um ficheiro validado contra o XSD oficial.
+        abort_unless($this->descarregavel($this->metadados($caminho)['estado']), 403, 'Ficheiro não validado contra o XSD oficial da AGT: não pode ser descarregado em produção.');
+
         return Storage::disk('local')->download($caminho, $ficheiro, ['Content-Type' => 'application/xml']);
     }
+
+    protected function descarregavel(string $estado): bool
+    {
+        return ! app()->isProduction() || $estado === SafTExportService::ESTADO_VALIDO;
+    }
+
+    /**
+     * Metadados gravados ao lado do XML (.json). Ficheiros antigos, sem metadados, contam como não validados.
+     *
+     * @return array{estado: string, sha256: ?string, erros_xsd: array<int, string>}
+     */
+    protected function metadados(string $caminhoXml): array
+    {
+        $disco = Storage::disk('local');
+        $caminhoMeta = preg_replace('/\.xml$/', '.json', $caminhoXml);
+        $meta = $disco->exists($caminhoMeta) ? json_decode((string) $disco->get($caminhoMeta), true) : null;
+        $meta = is_array($meta) ? $meta : [];
+
+        return [
+            'estado' => (string) ($meta['estado'] ?? SafTExportService::ESTADO_NAO_VALIDADO),
+            'sha256' => isset($meta['sha256']) ? (string) $meta['sha256'] : null,
+            'erros_xsd' => array_values(array_map('strval', (array) ($meta['erros_xsd'] ?? []))),
+        ];
+    }
 }
diff --git a/Modules/Faturacao/app/Http/Requests/GuardarFaturaRequest.php b/Modules/Faturacao/app/Http/Requests/GuardarFaturaRequest.php
index 990a870..2320c45 100644
--- a/Modules/Faturacao/app/Http/Requests/GuardarFaturaRequest.php
+++ b/Modules/Faturacao/app/Http/Requests/GuardarFaturaRequest.php
@@ -25,6 +25,8 @@ public function rules(): array
             'linhas.*.descricao' => ['required', 'string', 'max:255'],
             'linhas.*.quantidade' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:10000'],
             'linhas.*.preco_unitario' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
+            // Só linhas a 0% levam motivo de isenção (a regra completa está em ValidacaoLinhas, nos serviços).
+            'linhas.*.motivo_isencao' => ['nullable', 'string', Rule::in(array_keys((array) config('faturacao.motivos_isencao', [])))],
             'linhas.*.taxa_iva' => [
                 'required',
                 'numeric',
diff --git a/Modules/Faturacao/app/Jobs/GerarSafTJob.php b/Modules/Faturacao/app/Jobs/GerarSafTJob.php
index 694366e..8a9b906 100644
--- a/Modules/Faturacao/app/Jobs/GerarSafTJob.php
+++ b/Modules/Faturacao/app/Jobs/GerarSafTJob.php
@@ -49,14 +49,35 @@ public function handle(SafTExportService $safTExportService): void
         $fim = Carbon::createFromFormat('Y-m-d', $this->dataFim)->endOfDay();
 
         $xml = $safTExportService->gerar($this->empresa, $inicio, $fim);
+        $validacao = $safTExportService->validar($xml);
 
-        $caminho = "faturacao/saft/{$this->empresa->id}/SAFT_{$inicio->toDateString()}_{$fim->toDateString()}.xml";
-        Storage::disk('local')->put($caminho, $xml);
+        // Cada geração é uma versão nova (nunca se substitui a anterior): a data/hora entra no nome.
+        $disco = Storage::disk('local');
+        $base = "faturacao/saft/{$this->empresa->id}/SAFT_{$inicio->toDateString()}_{$fim->toDateString()}_".now()->format('YmdHis');
+
+        for ($n = 2; $disco->exists("{$base}.xml"); $n++) {
+            $base = preg_replace('/(-\d+)?$/', '', $base)."-{$n}";
+        }
+
+        $disco->put("{$base}.xml", $xml);
+        $disco->put("{$base}.json", json_encode([
+            'empresa_id' => $this->empresa->id,
+            'inicio' => $inicio->toDateString(),
+            'fim' => $fim->toDateString(),
+            'gerado_em' => now()->toIso8601String(),
+            'sha256' => hash('sha256', $xml),
+            'tamanho_bytes' => strlen($xml),
+            'estrutura' => 'provisoria',
+            'estado' => $validacao['estado'],
+            'erros_xsd' => $validacao['erros'],
+            'numero_certificado' => (string) config('faturacao.saft_numero_certificado', '0'),
+        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
 
         Log::info('SAF-T (AO) gerado', [
             'empresa_id' => $this->empresa->id,
-            'caminho' => $caminho,
+            'caminho' => "{$base}.xml",
             'tamanho_bytes' => strlen($xml),
+            'estado' => $validacao['estado'],
         ]);
     }
 
diff --git a/Modules/Faturacao/app/Models/Fatura.php b/Modules/Faturacao/app/Models/Fatura.php
index 20b7fb4..4c3890e 100644
--- a/Modules/Faturacao/app/Models/Fatura.php
+++ b/Modules/Faturacao/app/Models/Fatura.php
@@ -37,6 +37,7 @@ class Fatura extends Model implements DocumentoFiscalInterface
         'hash_anterior',
         'chave_versao',
         'observacoes',
+        'rejeitado_documento_no',
     ];
 
     protected function casts(): array
diff --git a/Modules/Faturacao/app/Models/FaturaLinha.php b/Modules/Faturacao/app/Models/FaturaLinha.php
index 439b753..e277e96 100644
--- a/Modules/Faturacao/app/Models/FaturaLinha.php
+++ b/Modules/Faturacao/app/Models/FaturaLinha.php
@@ -20,6 +20,7 @@ class FaturaLinha extends Model
         'quantidade',
         'preco_unitario',
         'taxa_iva',
+        'motivo_isencao',
         'valor_sem_iva',
         'valor_iva',
         'valor_total',
diff --git a/Modules/Faturacao/app/Models/NotaCreditoDebitoLinha.php b/Modules/Faturacao/app/Models/NotaCreditoDebitoLinha.php
index 3d632e1..fee6660 100644
--- a/Modules/Faturacao/app/Models/NotaCreditoDebitoLinha.php
+++ b/Modules/Faturacao/app/Models/NotaCreditoDebitoLinha.php
@@ -16,6 +16,7 @@ class NotaCreditoDebitoLinha extends Model
         'quantidade',
         'preco_unitario',
         'taxa_iva',
+        'motivo_isencao',
         'valor_sem_iva',
         'valor_iva',
         'valor_total',
diff --git a/Modules/Faturacao/app/Services/AgtCorrecaoService.php b/Modules/Faturacao/app/Services/AgtCorrecaoService.php
new file mode 100644
index 0000000..8cb55a2
--- /dev/null
+++ b/Modules/Faturacao/app/Services/AgtCorrecaoService.php
@@ -0,0 +1,99 @@
+<?php
+
+namespace Modules\Faturacao\Services;
+
+use DomainException;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Models\AgtSubmissao;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\Produto;
+
+/**
+ * Correção de uma fatura que a AGT marcou como inválida ou rejeitada.
+ *
+ * A AGT não aceita reenviar o mesmo documentNo (regra E46): a correção é um
+ * documento NOVO — número novo na série — enviado com documentStatus "C" e com
+ * rejectedDocumentNo = número do documento rejeitado (o AgtDocumentoBuilder
+ * trata disso a partir de `faturas.rejeitado_documento_no`).
+ *
+ * Este serviço só cria o RASCUNHO da correção (cliente e linhas copiados da fatura
+ * rejeitada, que continua intacta na cadeia fiscal). Quem o cria revê-o e emite-o pelo
+ * fluxo normal. Notas de crédito, notas de débito e recibos rejeitados não são
+ * corrigidos aqui: os totais já creditados/recebidos dependem de decisão humana.
+ */
+class AgtCorrecaoService
+{
+    public function __construct(protected FaturaService $faturas) {}
+
+    /**
+     * Idempotente: se a correção já foi criada, devolve-a.
+     *
+     * @throws DomainException
+     */
+    public function criarRascunhoDeCorrecao(AgtSubmissao $submissao): Fatura
+    {
+        if (! in_array($submissao->estado, [AgtSubmissao::INVALIDO, AgtSubmissao::REJEITADO], true)) {
+            throw new DomainException('Só se corrigem documentos que a AGT marcou como inválidos ou rejeitados; falhas técnicas resolvem-se com o reenvio.');
+        }
+
+        if ($submissao->documento_type !== Fatura::class) {
+            throw new DomainException('A criação automática de correções só existe para faturas. Notas de crédito, notas de débito e recibos rejeitados exigem tratamento manual (consulta os erros devolvidos pela AGT).');
+        }
+
+        return DB::transaction(function () use ($submissao): Fatura {
+            // Serializa pedidos simultâneos de correção do mesmo documento.
+            $atual = AgtSubmissao::query()->lockForUpdate()->findOrFail($submissao->id);
+            $empresaId = (int) $atual->empresa_id;
+
+            $existente = Fatura::withoutGlobalScopes()
+                ->where('empresa_id', $empresaId)
+                ->where('rejeitado_documento_no', $atual->documento_no)
+                ->first();
+
+            if ($existente) {
+                return $existente;
+            }
+
+            $original = Fatura::withoutGlobalScopes()
+                ->where('empresa_id', $empresaId)
+                ->with('linhas')
+                ->findOrFail($atual->documento_id);
+
+            $empresa = Empresa::query()->findOrFail($empresaId);
+            $cliente = Cliente::withoutGlobalScopes()->where('empresa_id', $empresaId)->findOrFail($original->cliente_id);
+
+            // Um produto entretanto apagado não impede a correção: a linha segue sem ligação ao produto.
+            $produtosAtivos = Produto::withoutGlobalScopes()
+                ->where('empresa_id', $empresaId)
+                ->whereNull('deleted_at')
+                ->whereIn('id', $original->linhas->pluck('produto_id')->filter()->all())
+                ->pluck('id')
+                ->all();
+
+            $linhas = $original->linhas->map(fn ($linha) => [
+                'produto_id' => in_array($linha->produto_id, $produtosAtivos, true) ? $linha->produto_id : null,
+                'descricao' => $linha->descricao,
+                'quantidade' => $linha->quantidade,
+                'preco_unitario' => $linha->preco_unitario,
+                'taxa_iva' => $linha->taxa_iva,
+                'motivo_isencao' => $linha->motivo_isencao,
+            ])->all();
+
+            $rascunho = $this->faturas->criarRascunho($empresa, $cliente, $linhas, $original->observacoes);
+            $rascunho->update(['rejeitado_documento_no' => $atual->documento_no]);
+
+            Log::warning('Rascunho de correção criado para documento rejeitado pela AGT', [
+                'submissao_id' => $atual->id,
+                'documento_rejeitado' => $atual->documento_no,
+                'rascunho_id' => $rascunho->id,
+                'estado_agt' => $atual->estado,
+                'erros_agt' => $atual->erros,
+            ]);
+
+            return $rascunho->fresh('linhas');
+        });
+    }
+}
diff --git a/Modules/Faturacao/app/Services/AgtDocumentoBuilder.php b/Modules/Faturacao/app/Services/AgtDocumentoBuilder.php
index 4e85cc6..decc841 100644
--- a/Modules/Faturacao/app/Services/AgtDocumentoBuilder.php
+++ b/Modules/Faturacao/app/Services/AgtDocumentoBuilder.php
@@ -34,9 +34,15 @@ public function construir(Fatura|NotaCreditoDebito|Recibo $documento): array
             throw AgtException::configuracaoInvalida("o documento {$documento->numero_documento} não está em AOA; facturas em divisa ainda não são suportadas.");
         }
 
+        // Correção de um documento rejeitado pela AGT: é um documento NOVO (número novo, regra E46)
+        // com documentStatus "C" e o número do documento rejeitado em rejectedDocumentNo.
+        $rejeitado = $documento->getAttribute('rejeitado_documento_no');
+        $correcao = is_string($rejeitado) && $rejeitado !== '' ? ['rejectedDocumentNo' => $rejeitado] : [];
+
         $resultado = [
             'documentNo' => $assinado['documentNo'],
-            'documentStatus' => 'N',
+            'documentStatus' => $correcao === [] ? 'N' : 'C',
+            ...$correcao,
             'jwsDocumentSignature' => $assinatura,
             'documentDate' => $assinado['documentDate'],
             'documentType' => $assinado['documentType'],
@@ -102,13 +108,7 @@ protected function linhas(Fatura|NotaCreditoDebito $documento): array
 
             // Nota de crédito a débito; os restantes documentos a crédito (regras E16/E17).
             $item[$ehNotaCredito ? 'debitAmount' : 'creditAmount'] = $valor;
-            $item['taxes'] = [[
-                'taxType' => 'IVA',
-                'taxCountryRegion' => 'AO',
-                'taxCode' => $this->codigoImposto((string) $linha->taxa_iva),
-                'taxPercentage' => NumeroJson::deDecimal((string) $linha->taxa_iva),
-                'taxContribution' => NumeroJson::deCentimos(Dinheiro::centimos($linha->valor_iva)),
-            ]];
+            $item['taxes'] = $this->impostos($documento, $linha);
             $item['settlementAmount'] = NumeroJson::deCentimos(0);
 
             $linhas[] = $item;
@@ -146,12 +146,96 @@ protected function fatura(int $empresaId, int $faturaId): ?Fatura
         return Fatura::withoutGlobalScopes()->where('empresa_id', $empresaId)->find($faturaId);
     }
 
-    protected function codigoImposto(string $taxa): string
+    /**
+     * Impostos da linha. Nota de débito: não há liquidação de imposto (Decreto Presidencial
+     * n.º 71/25, art. 3.º, al. m) — taxType NS. Linha a 0%: isenta (IVA/ISE) ou não sujeita
+     * (NS), conforme o motivo de isenção; ambos com taxExemptionCode obrigatório.
+     *
+     * @return array<int, array<string, mixed>>
+     */
+    protected function impostos(Fatura|NotaCreditoDebito $documento, object $linha): array
     {
+        $taxa = (string) $linha->taxa_iva;
+        $semImposto = (float) $taxa == 0.0 && Dinheiro::centimos($linha->valor_iva) === 0;
+
+        if ($documento instanceof NotaCreditoDebito && $documento->tipo === 'debito') {
+            if (! $semImposto) {
+                throw AgtException::configuracaoInvalida("a nota de débito {$documento->numero_documento} liquida IVA, o que o Regime Jurídico das Facturas não permite.");
+            }
+
+            return [$this->impostoIsento('NS', $this->codigoNaoSujeicaoDebito())];
+        }
+
         if ((float) $taxa == 0.0) {
-            throw AgtException::configuracaoInvalida('linhas isentas (IVA 0%) ainda não são suportadas na comunicação com a AGT: falta o código de isenção (Anexo 6.4).');
+            [$tipo, $codigo] = $this->motivoDeIsencao((string) ($linha->motivo_isencao ?? ''), (string) $documento->numero_documento);
+
+            return [$this->impostoIsento($tipo, $codigo)];
+        }
+
+        return [[
+            'taxType' => 'IVA',
+            'taxCountryRegion' => 'AO',
+            'taxCode' => $this->codigoImposto($taxa),
+            'taxPercentage' => NumeroJson::deDecimal($taxa),
+            'taxContribution' => NumeroJson::deCentimos(Dinheiro::centimos($linha->valor_iva)),
+        ]];
+    }
+
+    /** @return array<string, mixed> */
+    protected function impostoIsento(string $tipo, string $codigo): array
+    {
+        $imposto = ['taxType' => $tipo === 'NS' ? 'NS' : 'IVA', 'taxCountryRegion' => 'AO'];
+
+        if ($tipo !== 'NS') {
+            $imposto['taxCode'] = 'ISE';
+        }
+
+        return $imposto + [
+            'taxPercentage' => NumeroJson::deCentimos(0),
+            'taxContribution' => NumeroJson::deCentimos(0),
+            'taxExemptionCode' => $codigo,
+        ];
+    }
+
+    /** @return array{0: string, 1: string} [tipo (ISE|NS), código do motivo] */
+    protected function motivoDeIsencao(string $motivo, string $numeroDocumento): array
+    {
+        $this->garantirTabelaDeIsencaoConfirmada();
+
+        $codigo = strtoupper(trim($motivo));
+        $entrada = ((array) config('faturacao.motivos_isencao', []))[$codigo] ?? null;
+
+        if ($codigo === '' || ! is_array($entrada)) {
+            throw AgtException::configuracaoInvalida("a linha isenta do documento {$numeroDocumento} não tem um motivo de isenção válido (config faturacao.motivos_isencao).");
+        }
+
+        return [($entrada['tipo'] ?? 'ISE') === 'NS' ? 'NS' : 'ISE', $codigo];
+    }
+
+    protected function codigoNaoSujeicaoDebito(): string
+    {
+        $this->garantirTabelaDeIsencaoConfirmada();
+
+        $codigo = strtoupper(trim((string) config('faturacao.agt.nd_codigo_nao_sujeicao')));
+        $entrada = ((array) config('faturacao.motivos_isencao', []))[$codigo] ?? null;
+
+        if ($codigo === '' || ! is_array($entrada) || ($entrada['tipo'] ?? null) !== 'NS') {
+            throw AgtException::configuracaoInvalida('as notas de débito não liquidam imposto e seguem com taxType NS: define FATURACAO_AGT_ND_CODIGO_NS com um código de não sujeição da tabela faturacao.motivos_isencao (Anexo 6.4).');
+        }
+
+        return $codigo;
+    }
+
+    /** Nada de códigos por confirmar a caminho da AGT. */
+    protected function garantirTabelaDeIsencaoConfirmada(): void
+    {
+        if (! config('faturacao.motivos_isencao_confirmados')) {
+            throw AgtException::configuracaoInvalida('a tabela de motivos de isenção ainda não foi confirmada contra o Anexo 6.4 (FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS=true).');
         }
+    }
 
+    protected function codigoImposto(string $taxa): string
+    {
         $mapa = (array) config('faturacao.agt.codigos_taxa', []);
         $chave = number_format((float) $taxa, 2, '.', '');
 
@@ -160,6 +244,6 @@ protected function codigoImposto(string $taxa): string
 
     protected function numero(mixed $valor): NumeroJson
     {
-        return NumeroJson::deCentimos((int) round(((float) $valor) * 100));
+        return NumeroJson::deCentimos(Dinheiro::centimos($valor));
     }
 }
diff --git a/Modules/Faturacao/app/Services/AgtSubmissaoService.php b/Modules/Faturacao/app/Services/AgtSubmissaoService.php
index 1975eaa..c78bb27 100644
--- a/Modules/Faturacao/app/Services/AgtSubmissaoService.php
+++ b/Modules/Faturacao/app/Services/AgtSubmissaoService.php
@@ -116,7 +116,7 @@ public function marcarSemResposta(?int $empresaId = null): int
     public function reenviar(AgtSubmissao $submissao): AgtSubmissao
     {
         if (! in_array($submissao->estado, [AgtSubmissao::ERRO, AgtSubmissao::AGUARDA_INTERVENCAO], true)) {
-            throw new \DomainException('Só se reenviam submissões em erro técnico ou a aguardar intervenção; documentos inválidos ou rejeitados exigem um documento de correção.');
+            throw new \DomainException('Só se reenviam submissões em erro técnico ou a aguardar intervenção; documentos inválidos ou rejeitados exigem um documento de correção (php artisan faturacao:agt-corrigir <id da submissão>).');
         }
 
         Log::warning('Submissão AGT reenviada manualmente', [
diff --git a/Modules/Faturacao/app/Services/AssinaturaFiscalService.php b/Modules/Faturacao/app/Services/AssinaturaFiscalService.php
index 0035ec5..fafa43f 100644
--- a/Modules/Faturacao/app/Services/AssinaturaFiscalService.php
+++ b/Modules/Faturacao/app/Services/AssinaturaFiscalService.php
@@ -75,7 +75,13 @@ public function verificar(string $conteudo, string $jws, string $caminhoChavePub
             throw AssinaturaFiscalException::chavePublicaNaoEncontrada();
         }
 
-        $chavePublica = openssl_pkey_get_public((string) file_get_contents($caminhoChavePublica));
+        return $this->verificarComPem($conteudo, $jws, (string) file_get_contents($caminhoChavePublica));
+    }
+
+    /** Como verificar(), com a chave pública já em PEM. */
+    public function verificarComPem(string $conteudo, string $jws, string $chavePublicaPem): bool
+    {
+        $chavePublica = openssl_pkey_get_public($chavePublicaPem);
 
         if (! $chavePublica) {
             throw AssinaturaFiscalException::chavePublicaInvalida();
@@ -84,6 +90,45 @@ public function verificar(string $conteudo, string $jws, string $caminhoChavePub
         return Jws::verificar($jws, $chavePublica) && Jws::payload($jws) === $conteudo;
     }
 
+    /**
+     * Chave pública (PEM) derivada da chave privada DA EMPRESA ({chaves_dir}/{NIF}.pem),
+     * ou null se essa chave não existe neste servidor. Nunca recorre à chave genérica.
+     */
+    public function chavePublicaDaEmpresa(int $empresaId): ?string
+    {
+        $caminho = $this->caminhoDaChaveDaEmpresa($empresaId);
+
+        if ($this->nifSeguro($empresaId) === '' || ! is_file($caminho)) {
+            return null;
+        }
+
+        $chave = openssl_pkey_get_private((string) file_get_contents($caminho));
+
+        if (! $chave) {
+            throw AssinaturaFiscalException::chavePrivadaInvalida();
+        }
+
+        $publica = (openssl_pkey_get_details($chave) ?: [])['key'] ?? null;
+
+        return is_string($publica) ? $publica : null;
+    }
+
+    /** Caminho esperado da chave privada da empresa: {chaves_dir}/{NIF}.pem. */
+    public function caminhoDaChaveDaEmpresa(int $empresaId): string
+    {
+        $nif = $this->nifSeguro($empresaId);
+
+        return rtrim((string) config('faturacao.chaves_dir'), '/\\').DIRECTORY_SEPARATOR.($nif !== '' ? $nif : 'SEM-NIF').'.pem';
+    }
+
+    /** NIF só com caracteres seguros para um nome de ficheiro (sem '/', '..' etc.); '' se a empresa não tem NIF. */
+    protected function nifSeguro(int $empresaId): string
+    {
+        $nif = (string) Empresa::query()->whereKey($empresaId)->value('nif');
+
+        return preg_replace('/[^A-Za-z0-9]/', '', $nif) ?? '';
+    }
+
     protected function carregarChavePrivada(?int $empresaId): OpenSSLAsymmetricKey
     {
         $caminho = $this->caminhoDaChave($empresaId);
@@ -118,14 +163,16 @@ protected function carregarChavePrivada(?int $empresaId): OpenSSLAsymmetricKey
 
     protected function caminhoDaChave(?int $empresaId): string
     {
-        if ($empresaId !== null) {
-            $nif = (string) Empresa::query()->whereKey($empresaId)->value('nif');
-            // Só caracteres seguros no nome do ficheiro (sem '/', '..' etc.).
-            $nif = preg_replace('/[^A-Za-z0-9]/', '', $nif) ?? '';
+        // Sem empresa não há chave de emissor: fora de desenvolvimento/testes nunca se
+        // assina (nem se assina um pedido à AGT) com a chave genérica.
+        if ($empresaId === null && ! $this->permiteChaveGenerica()) {
+            throw AssinaturaFiscalException::chavePrivadaNaoEncontrada('(sem empresa: a chave por NIF não pode ser resolvida)');
+        }
 
-            $porEmpresa = rtrim((string) config('faturacao.chaves_dir'), '/\\').DIRECTORY_SEPARATOR.($nif !== '' ? $nif : 'SEM-NIF').'.pem';
+        if ($empresaId !== null) {
+            $porEmpresa = $this->caminhoDaChaveDaEmpresa($empresaId);
 
-            if ($nif !== '' && is_file($porEmpresa)) {
+            if ($this->nifSeguro($empresaId) !== '' && is_file($porEmpresa)) {
                 return $porEmpresa;
             }
 
diff --git a/Modules/Faturacao/app/Services/FaturaService.php b/Modules/Faturacao/app/Services/FaturaService.php
index 33ca847..ac93195 100644
--- a/Modules/Faturacao/app/Services/FaturaService.php
+++ b/Modules/Faturacao/app/Services/FaturaService.php
@@ -126,7 +126,7 @@ public function emitir(Fatura $fatura): Fatura
     }
 
     /**
-     * @param  array<int, array{produto_id?: int, descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
+     * @param  array<int, array{produto_id?: int, descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float, motivo_isencao?: string|null}>  $linhas
      */
     protected function substituirLinhas(Fatura $fatura, array $linhas): void
     {
@@ -140,6 +140,7 @@ protected function substituirLinhas(Fatura $fatura, array $linhas): void
                 'quantidade' => $dadosLinha['quantidade'],
                 'preco_unitario' => $dadosLinha['preco_unitario'],
                 'taxa_iva' => $dadosLinha['taxa_iva'],
+                'motivo_isencao' => ValidacaoLinhas::normalizarMotivo($dadosLinha['motivo_isencao'] ?? null),
                 'ordem' => $indice,
             ]);
 
diff --git a/Modules/Faturacao/app/Services/NotaCreditoDebitoService.php b/Modules/Faturacao/app/Services/NotaCreditoDebitoService.php
index 7ad0b96..6e860e9 100644
--- a/Modules/Faturacao/app/Services/NotaCreditoDebitoService.php
+++ b/Modules/Faturacao/app/Services/NotaCreditoDebitoService.php
@@ -6,6 +6,7 @@
 use Illuminate\Support\Facades\Log;
 use Modules\Faturacao\Models\Cliente;
 use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\FaturaLinha;
 use Modules\Faturacao\Models\NotaCreditoDebito;
 use Modules\Faturacao\Models\NotaCreditoDebitoLinha;
 use Modules\Faturacao\Support\Dinheiro;
@@ -63,6 +64,7 @@ protected function prepararAnulacaoTotal(Fatura $fatura, string $motivo): NotaCr
             'quantidade' => $linha->quantidade,
             'preco_unitario' => $linha->preco_unitario,
             'taxa_iva' => $linha->taxa_iva,
+            'motivo_isencao' => $linha->motivo_isencao,
         ])->all();
 
         return $this->criarRascunho($fatura->empresa_id, $fatura->cliente_id, 'credito', $motivo, $linhas, $fatura->id);
@@ -101,8 +103,15 @@ public function criarRascunho(
             throw new \DomainException('O cliente não pertence a esta empresa.');
         }
 
-        // Notas de crédito espelham a fatura original: não se re-verificam as taxas.
-        ValidacaoLinhas::validar($linhas, $empresaId, verificarTaxas: $tipo !== 'credito');
+        // Nota de débito: não há liquidação de imposto (Decreto Presidencial n.º 71/25,
+        // art. 3.º, al. m) — todas as linhas ficam a 0%, sem motivo de isenção próprio.
+        if ($tipo === 'debito') {
+            $linhas = $this->normalizarLinhasDeDebito($linhas);
+        }
+
+        // As notas espelham o documento de origem (crédito) ou não liquidam imposto (débito):
+        // a lista de taxas permitidas não se aplica; as isenções de um crédito têm de vir com motivo.
+        ValidacaoLinhas::validar($linhas, $empresaId, verificarTaxas: false, exigirMotivoIsencao: $tipo === 'credito');
 
         if ($faturaId !== null) {
             $origem = Fatura::withoutGlobalScopes()->where('empresa_id', $empresaId)->find($faturaId);
@@ -137,6 +146,7 @@ public function criarRascunho(
                     'quantidade' => $dadosLinha['quantidade'],
                     'preco_unitario' => $dadosLinha['preco_unitario'],
                     'taxa_iva' => $dadosLinha['taxa_iva'],
+                    'motivo_isencao' => ValidacaoLinhas::normalizarMotivo($dadosLinha['motivo_isencao'] ?? null),
                     'ordem' => $indice,
                 ]))->calcularValores()->save();
             }
@@ -170,6 +180,8 @@ public function emitir(NotaCreditoDebito $nota): NotaCreditoDebito
                     throw new \DomainException('Não é possível emitir uma nota sem linhas.');
                 }
 
+                $this->garantirDebitoSemIva($nota);
+
                 $this->recalcularTotais($nota);
                 $this->garantirLimiteDeCredito($nota);
 
@@ -240,6 +252,101 @@ protected function garantirLimiteDeCredito(NotaCreditoDebito $nota): void
         if ($jaCreditado + Dinheiro::centimos($nota->valor_total) > Dinheiro::centimos($fatura->valor_total)) {
             throw new \DomainException('O total creditado excede o valor da fatura de origem.');
         }
+
+        $this->garantirLimitePorTaxa($nota, $fatura);
+    }
+
+    /**
+     * Além do total, o valor creditado SEM IVA, taxa a taxa, nunca excede o da fatura
+     * (a AGT valida o mesmo: regras E41/E42). Uma nota a uma taxa que a fatura não tem
+     * também fica de fora. Compara-se a base (sem IVA): o IVA arredondado por excesso
+     * linha a linha impediria créditos parciais legítimos.
+     */
+    protected function garantirLimitePorTaxa(NotaCreditoDebito $nota, Fatura $fatura): void
+    {
+        $origem = $this->basePorTaxa(FaturaLinha::query()->where('fatura_id', $fatura->id)->get(['taxa_iva', 'valor_sem_iva']));
+
+        $idsAnteriores = NotaCreditoDebito::withoutGlobalScopes()
+            ->where('empresa_id', $nota->empresa_id)
+            ->where('fatura_id', $fatura->id)
+            ->where('tipo', 'credito')
+            ->where('estado', 'emitida')
+            ->where('id', '!=', $nota->id)
+            ->pluck('id');
+
+        $creditado = $this->basePorTaxa(
+            NotaCreditoDebitoLinha::query()->whereIn('nota_credito_debito_id', $idsAnteriores)->get(['taxa_iva', 'valor_sem_iva'])
+        );
+
+        $atual = $this->basePorTaxa($nota->linhas()->get(['taxa_iva', 'valor_sem_iva']));
+
+        foreach ($atual as $taxa => $valor) {
+            if (($creditado[$taxa] ?? 0) + $valor > ($origem[$taxa] ?? 0)) {
+                throw new \DomainException("O valor creditado à taxa de {$taxa}% excede o da fatura de origem (ou a fatura não tem linhas a essa taxa).");
+            }
+        }
+    }
+
+    /**
+     * @param  iterable<int, object>  $linhas
+     * @return array<string, int> cêntimos sem IVA por taxa ('14.00' => 1000)
+     */
+    protected function basePorTaxa(iterable $linhas): array
+    {
+        $soma = [];
+
+        foreach ($linhas as $linha) {
+            $taxa = number_format((float) $linha->taxa_iva, 2, '.', '');
+            $soma[$taxa] = ($soma[$taxa] ?? 0) + Dinheiro::centimos($linha->valor_sem_iva);
+        }
+
+        return $soma;
+    }
+
+    /**
+     * Última barreira antes de assinar: um rascunho de débito criado por outro
+     * caminho não pode conter IVA.
+     */
+    protected function garantirDebitoSemIva(NotaCreditoDebito $nota): void
+    {
+        if ($nota->tipo === 'debito' && $nota->linhas()->where(fn ($q) => $q->where('taxa_iva', '!=', 0)->orWhere('valor_iva', '!=', 0))->exists()) {
+            throw new \DomainException('Uma nota de débito não pode liquidar IVA (Decreto Presidencial n.º 71/25, art. 3.º, al. m).');
+        }
+    }
+
+    /**
+     * Nota de débito: a taxa omitida vale 0; qualquer taxa acima de 0 é recusada.
+     *
+     * @param  array<int, array<string, mixed>>  $linhas
+     * @return array<int, array<string, mixed>>
+     */
+    protected function normalizarLinhasDeDebito(array $linhas): array
+    {
+        $normalizadas = [];
+
+        foreach (array_values($linhas) as $indice => $linha) {
+            $taxa = $linha['taxa_iva'] ?? 0;
+
+            if (is_string($taxa) && trim($taxa) === '') {
+                $taxa = 0;
+            }
+
+            $n = $indice + 1;
+
+            if (! is_scalar($taxa)) {
+                throw new \InvalidArgumentException("Linha {$n}: a taxa de IVA é inválida.");
+            }
+
+            if (Dinheiro::centimos($taxa) !== 0) {
+                throw new \DomainException("Linha {$n}: uma nota de débito não pode liquidar IVA (Decreto Presidencial n.º 71/25, art. 3.º, al. m); usa a taxa 0.");
+            }
+
+            $linha['taxa_iva'] = 0;
+            $linha['motivo_isencao'] = null;
+            $normalizadas[] = $linha;
+        }
+
+        return $normalizadas;
     }
 
     protected function recalcularTotais(NotaCreditoDebito $nota): void
diff --git a/Modules/Faturacao/app/Services/SafTExportService.php b/Modules/Faturacao/app/Services/SafTExportService.php
index e553b91..9a74888 100644
--- a/Modules/Faturacao/app/Services/SafTExportService.php
+++ b/Modules/Faturacao/app/Services/SafTExportService.php
@@ -14,14 +14,28 @@
 
 /**
  * ESTRUTURA PROVISÓRIA do SAF-T (AO): ainda NÃO validada contra o XSD oficial
- * da AGT (faltam namespace, TaxTable, DocumentTotals, etc.). Não submeter à AGT
- * sem validação XSD e software certificado — ver README.
+ * da AGT (falta, por exemplo, o namespace e o tratamento dos recibos). Tem
+ * TaxTable e DocumentTotals, mas só deixa de ser provisória quando o XSD oficial
+ * (faturacao.saft.xsd_path) a aceitar — validar() devolve o resultado. Não submeter
+ * à AGT sem validação XSD e software certificado — ver README.
  *
  * Todas as queries filtram explicitamente por empresa_id e ignoram a
  * TenantScope, por isso não dependem de tenant ambiente (jobs/comandos).
  */
 class SafTExportService
 {
+    public const ESTADO_VALIDO = 'valido_xsd';
+
+    public const ESTADO_INVALIDO = 'invalido_xsd';
+
+    public const ESTADO_NAO_VALIDADO = 'nao_validado';
+
+    public const ROTULOS = [
+        self::ESTADO_VALIDO => 'Validado contra o XSD oficial',
+        self::ESTADO_INVALIDO => 'Rejeitado pela validação XSD',
+        self::ESTADO_NAO_VALIDADO => 'Não validado (sem XSD oficial configurado)',
+    ];
+
     public function gerar(Empresa $empresa, Carbon $inicio, Carbon $fim): string
     {
         if ($this->exportacaoBloqueada()) {
@@ -31,16 +45,56 @@ public function gerar(Empresa $empresa, Carbon $inicio, Carbon $fim): string
         $doc = new DOMDocument('1.0', 'UTF-8');
         $doc->formatOutput = true;
 
+        $doc->appendChild($doc->createComment(' ESTRUTURA PROVISÓRIA: não validada contra o XSD oficial da AGT. Não submeter sem validação e certificação. '));
+
         $raiz = $doc->createElement('AuditFile');
         $doc->appendChild($raiz);
 
+        // Os documentos geram-se primeiro: a TaxTable lista os impostos efetivamente usados no período.
+        $impostosUsados = [];
+        $fonte = $this->documentosFonte($doc, $empresa, $inicio, $fim, $impostosUsados);
+
         $raiz->appendChild($this->cabecalho($doc, $empresa, $inicio, $fim));
-        $raiz->appendChild($this->tabelasMestras($doc, $empresa));
-        $raiz->appendChild($this->documentosFonte($doc, $empresa, $inicio, $fim));
+        $raiz->appendChild($this->tabelasMestras($doc, $empresa, $impostosUsados));
+        $raiz->appendChild($fonte);
 
         return $doc->saveXML();
     }
 
+    /**
+     * Valida o XML contra o XSD oficial (faturacao.saft.xsd_path). Sem XSD configurado
+     * devolve 'nao_validado' — nunca finge que o ficheiro está validado.
+     *
+     * @return array{estado: string, erros: array<int, string>}
+     */
+    public function validar(string $xml): array
+    {
+        $xsd = config('faturacao.saft.xsd_path');
+
+        if (! is_string($xsd) || $xsd === '' || ! is_file($xsd)) {
+            return ['estado' => self::ESTADO_NAO_VALIDADO, 'erros' => []];
+        }
+
+        $anterior = libxml_use_internal_errors(true);
+        libxml_clear_errors();
+
+        try {
+            $dom = new DOMDocument;
+            $valido = $dom->loadXML($xml) && @$dom->schemaValidate($xsd);
+            $erros = array_map(
+                fn (\LibXMLError $erro) => trim($erro->message).' (linha '.$erro->line.')',
+                array_slice(libxml_get_errors(), 0, 20),
+            );
+        } finally {
+            libxml_clear_errors();
+            libxml_use_internal_errors($anterior);
+        }
+
+        return $valido
+            ? ['estado' => self::ESTADO_VALIDO, 'erros' => []]
+            : ['estado' => self::ESTADO_INVALIDO, 'erros' => $erros !== [] ? $erros : ['O XML não cumpre o XSD configurado.']];
+    }
+
     /** Em produção, o SAF-T fica bloqueado enquanto não houver número de certificação AGT. */
     public function exportacaoBloqueada(): bool
     {
@@ -102,7 +156,8 @@ protected function cabecalho(DOMDocument $doc, Empresa $empresa, Carbon $inicio,
         return $cab;
     }
 
-    protected function tabelasMestras(DOMDocument $doc, Empresa $empresa): DOMElement
+    /** @param  array<string, array{0: string, 1: string, 2: string}>  $impostosUsados */
+    protected function tabelasMestras(DOMDocument $doc, Empresa $empresa, array $impostosUsados = []): DOMElement
     {
         $tabelas = $doc->createElement('MasterFiles');
 
@@ -124,10 +179,34 @@ protected function tabelasMestras(DOMDocument $doc, Empresa $empresa): DOMElemen
             $tabelas->appendChild($p);
         }
 
+        $tabelas->appendChild($this->tabelaDeImpostos($doc, $impostosUsados));
+
         return $tabelas;
     }
 
-    protected function documentosFonte(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim): DOMElement
+    /** @param  array<string, array{0: string, 1: string, 2: string}>  $impostosUsados  [tipo, código, taxa] por chave */
+    protected function tabelaDeImpostos(DOMDocument $doc, array $impostosUsados): DOMElement
+    {
+        $descricoes = ['NOR' => 'Taxa normal', 'INT' => 'Taxa intermédia', 'RED' => 'Taxa reduzida', 'ISE' => 'Isento', 'OUT' => 'Outra taxa', 'NS' => 'Não sujeito'];
+        $tabela = $doc->createElement('TaxTable');
+
+        ksort($impostosUsados);
+
+        foreach ($impostosUsados as [$tipo, $codigo, $taxa]) {
+            $entrada = $doc->createElement('TaxTableEntry');
+            $entrada->appendChild($this->no($doc, 'TaxType', $tipo));
+            $entrada->appendChild($this->no($doc, 'TaxCountryRegion', 'AO'));
+            $entrada->appendChild($this->no($doc, 'TaxCode', $codigo));
+            $entrada->appendChild($this->no($doc, 'Description', $descricoes[$codigo] ?? $codigo));
+            $entrada->appendChild($this->no($doc, 'TaxPercentage', $taxa));
+            $tabela->appendChild($entrada);
+        }
+
+        return $tabela;
+    }
+
+    /** @param  array<string, array{0: string, 1: string, 2: string}>  $impostosUsados */
+    protected function documentosFonte(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim, array &$impostosUsados): DOMElement
     {
         $fonte = $doc->createElement('SourceDocuments');
         $vendas = $doc->createElement('SalesInvoices');
@@ -152,7 +231,7 @@ protected function documentosFonte(DOMDocument $doc, Empresa $empresa, Carbon $i
             ->with('linhas')->orderBy('serie_id')->orderBy('numero_sequencial')->lazy(200);
 
         foreach ($faturas as $f) {
-            $vendas->appendChild($this->documento($doc, 'FT', $f, 'CreditAmount'));
+            $vendas->appendChild($this->documento($doc, 'FT', $f, 'CreditAmount', $impostosUsados));
             $totalCredito += Dinheiro::centimos($f->valor_sem_iva);
             $entradas++;
         }
@@ -164,7 +243,7 @@ protected function documentosFonte(DOMDocument $doc, Empresa $empresa, Carbon $i
 
         foreach ($notas as $n) {
             $ehCredito = $n->tipo === 'credito';
-            $vendas->appendChild($this->documento($doc, $n->tipoDocumentoFiscal(), $n, $ehCredito ? 'DebitAmount' : 'CreditAmount'));
+            $vendas->appendChild($this->documento($doc, $n->tipoDocumentoFiscal(), $n, $ehCredito ? 'DebitAmount' : 'CreditAmount', $impostosUsados));
             $ehCredito
                 ? $totalDebito += Dinheiro::centimos($n->valor_sem_iva)
                 : $totalCredito += Dinheiro::centimos($n->valor_sem_iva);
@@ -180,7 +259,8 @@ protected function documentosFonte(DOMDocument $doc, Empresa $empresa, Carbon $i
         return $fonte;
     }
 
-    protected function documento(DOMDocument $doc, string $tipo, Fatura|NotaCreditoDebito $m, string $campoValor): DOMElement
+    /** @param  array<string, array{0: string, 1: string, 2: string}>  $impostosUsados */
+    protected function documento(DOMDocument $doc, string $tipo, Fatura|NotaCreditoDebito $m, string $campoValor, array &$impostosUsados): DOMElement
     {
         $no = $doc->createElement('Invoice');
 
@@ -192,20 +272,70 @@ protected function documento(DOMDocument $doc, string $tipo, Fatura|NotaCreditoD
         $no->appendChild($this->no($doc, 'CustomerID', $m->cliente_id));
         $no->appendChild($this->no($doc, 'Hash', $m->hash));
         $no->appendChild($this->no($doc, 'HashControl', $m->chave_versao));
-        $no->appendChild($this->no($doc, 'GrossTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_total))));
-        $no->appendChild($this->no($doc, 'NetTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_sem_iva))));
-        $no->appendChild($this->no($doc, 'TaxPayable', Dinheiro::formatar(Dinheiro::centimos($m->valor_iva))));
 
         foreach ($m->linhas as $linha) {
+            [$tipoImposto, $codigoImposto, $motivo] = $this->classificarImposto($m, $linha);
+            $taxa = number_format((float) $linha->taxa_iva, 2, '.', '');
+            $impostosUsados["{$tipoImposto}|{$codigoImposto}|{$taxa}"] = [$tipoImposto, $codigoImposto, $taxa];
+
             $l = $doc->createElement('Line');
             $l->appendChild($this->no($doc, 'Description', $linha->descricao));
             $l->appendChild($this->no($doc, 'Quantity', $linha->quantidade));
             $l->appendChild($this->no($doc, 'UnitPrice', Dinheiro::formatar(Dinheiro::centimos($linha->preco_unitario))));
             $l->appendChild($this->no($doc, $campoValor, Dinheiro::formatar(Dinheiro::centimos($linha->valor_sem_iva))));
-            $l->appendChild($this->no($doc, 'TaxPercentage', $linha->taxa_iva));
+
+            $imposto = $doc->createElement('Tax');
+            $imposto->appendChild($this->no($doc, 'TaxType', $tipoImposto));
+            $imposto->appendChild($this->no($doc, 'TaxCountryRegion', 'AO'));
+            $imposto->appendChild($this->no($doc, 'TaxCode', $codigoImposto));
+            $imposto->appendChild($this->no($doc, 'TaxPercentage', $taxa));
+            $l->appendChild($imposto);
+
+            if ($motivo !== null) {
+                $l->appendChild($this->no($doc, 'TaxExemptionCode', $motivo));
+            }
+
             $no->appendChild($l);
         }
 
+        $totais = $doc->createElement('DocumentTotals');
+        $totais->appendChild($this->no($doc, 'TaxPayable', Dinheiro::formatar(Dinheiro::centimos($m->valor_iva))));
+        $totais->appendChild($this->no($doc, 'NetTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_sem_iva))));
+        $totais->appendChild($this->no($doc, 'GrossTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_total))));
+        $no->appendChild($totais);
+
         return $no;
     }
+
+    /**
+     * Mesma classificação que segue para a AGT (AgtDocumentoBuilder): IVA com código da
+     * taxa, isento (IVA/ISE) ou não sujeito (NS); nota de débito é sempre não sujeita.
+     *
+     * @return array{0: string, 1: string, 2: ?string} [taxType, taxCode, taxExemptionCode]
+     */
+    protected function classificarImposto(Fatura|NotaCreditoDebito $documento, object $linha): array
+    {
+        $taxa = (float) $linha->taxa_iva;
+
+        if ($taxa > 0) {
+            $codigos = (array) config('faturacao.agt.codigos_taxa', []);
+
+            return ['IVA', (string) ($codigos[number_format($taxa, 2, '.', '')] ?? 'OUT'), null];
+        }
+
+        if ($documento instanceof NotaCreditoDebito && $documento->tipo === 'debito') {
+            $codigo = strtoupper(trim((string) config('faturacao.agt.nd_codigo_nao_sujeicao')));
+
+            return ['NS', 'NS', $codigo !== '' ? $codigo : null];
+        }
+
+        $motivo = strtoupper(trim((string) ($linha->motivo_isencao ?? '')));
+        $entrada = ((array) config('faturacao.motivos_isencao', []))[$motivo] ?? null;
+
+        if (is_array($entrada) && ($entrada['tipo'] ?? 'ISE') === 'NS') {
+            return ['NS', 'NS', $motivo];
+        }
+
+        return ['IVA', 'ISE', $motivo !== '' ? $motivo : null];
+    }
 }
diff --git a/Modules/Faturacao/app/Support/ValidacaoLinhas.php b/Modules/Faturacao/app/Support/ValidacaoLinhas.php
index 73dcb47..2cd5004 100644
--- a/Modules/Faturacao/app/Support/ValidacaoLinhas.php
+++ b/Modules/Faturacao/app/Support/ValidacaoLinhas.php
@@ -13,12 +13,13 @@ final class ValidacaoLinhas
 {
     /**
      * @param  array<int, array<string, mixed>>  $linhas
-     * @param  bool  $verificarTaxas  false em notas de crédito, que espelham a fatura original
+     * @param  bool  $verificarTaxas  false em notas de crédito (espelham a fatura original) e de débito (não liquidam IVA)
+     * @param  bool  $exigirMotivoIsencao  false em notas de débito: não há liquidação de imposto e o código de não sujeição vem da configuração
      *
      * @throws \InvalidArgumentException dados malformados
-     * @throws \DomainException regra de negócio (taxa, produto)
+     * @throws \DomainException regra de negócio (taxa, isenção, produto)
      */
-    public static function validar(array $linhas, int $empresaId, bool $verificarTaxas = true): void
+    public static function validar(array $linhas, int $empresaId, bool $verificarTaxas = true, bool $exigirMotivoIsencao = true): void
     {
         foreach (array_values($linhas) as $indice => $linha) {
             $n = $indice + 1;
@@ -27,13 +28,24 @@ public static function validar(array $linhas, int $empresaId, bool $verificarTax
                 throw new \InvalidArgumentException("Linha {$n}: a descrição é obrigatória.");
             }
 
-            if (! array_key_exists('taxa_iva', $linha)) {
-                throw new \InvalidArgumentException("Linha {$n}: a taxa de IVA é obrigatória.");
+            // Sem valores por omissão: um preço ou uma taxa em falta nunca se tornam 0 em silêncio.
+            $obrigatorios = [
+                'quantidade' => 'a quantidade é obrigatória',
+                'preco_unitario' => 'o preço unitário é obrigatório',
+                'taxa_iva' => 'a taxa de IVA é obrigatória',
+            ];
+
+            foreach ($obrigatorios as $campo => $mensagem) {
+                $valor = $linha[$campo] ?? null;
+
+                if (! is_scalar($valor) || trim((string) $valor) === '') {
+                    throw new \InvalidArgumentException("Linha {$n}: {$mensagem}.");
+                }
             }
 
             // Quantidade, preço e taxa: a própria aritmética rejeita o que é inválido.
             try {
-                Dinheiro::calcularLinha($linha['quantidade'] ?? 0, $linha['preco_unitario'] ?? 0, $linha['taxa_iva']);
+                Dinheiro::calcularLinha($linha['quantidade'], $linha['preco_unitario'], $linha['taxa_iva']);
             } catch (\InvalidArgumentException $e) {
                 throw new \InvalidArgumentException("Linha {$n}: ".$e->getMessage(), 0, $e);
             }
@@ -42,6 +54,10 @@ public static function validar(array $linhas, int $empresaId, bool $verificarTax
                 self::validarTaxa((float) $linha['taxa_iva'], $n);
             }
 
+            if ($exigirMotivoIsencao) {
+                self::validarMotivoIsencao((float) $linha['taxa_iva'], $linha['motivo_isencao'] ?? null, $n);
+            }
+
             $produtoId = $linha['produto_id'] ?? null;
 
             if ($produtoId !== null && ! Produto::withoutGlobalScopes()
@@ -54,6 +70,16 @@ public static function validar(array $linhas, int $empresaId, bool $verificarTax
         }
     }
 
+    /** Código de motivo normalizado (maiúsculas, sem espaços); null se vazio. */
+    public static function normalizarMotivo(mixed $motivo): ?string
+    {
+        if (! is_string($motivo) || trim($motivo) === '') {
+            return null;
+        }
+
+        return strtoupper(trim($motivo));
+    }
+
     protected static function validarTaxa(float $taxa, int $n): void
     {
         $permitida = false;
@@ -70,7 +96,8 @@ protected static function validarTaxa(float $taxa, int $n): void
         }
 
         // Com a AGT ativa, uma taxa sem código AGT só falharia no envio: recusa-se já.
-        if (config('faturacao.agt.ativo')) {
+        // (A taxa 0 não tem taxCode próprio: identifica-se pelo motivo de isenção.)
+        if (config('faturacao.agt.ativo') && $taxa > 0) {
             $codigos = (array) config('faturacao.agt.codigos_taxa', []);
 
             if (! isset($codigos[number_format($taxa, 2, '.', '')])) {
@@ -78,4 +105,35 @@ protected static function validarTaxa(float $taxa, int $n): void
             }
         }
     }
+
+    /** Linhas a 0% exigem motivo de isenção da tabela; linhas com imposto não levam motivo. */
+    protected static function validarMotivoIsencao(float $taxa, mixed $bruto, int $n): void
+    {
+        if ($bruto !== null && ! is_string($bruto)) {
+            throw new \InvalidArgumentException("Linha {$n}: o motivo de isenção tem de ser um código (ex.: M10).");
+        }
+
+        $motivo = self::normalizarMotivo($bruto);
+
+        if ($taxa > 0) {
+            if ($motivo !== null) {
+                throw new \DomainException("Linha {$n}: só as linhas isentas (IVA 0%) levam motivo de isenção.");
+            }
+
+            return;
+        }
+
+        if ($motivo === null) {
+            throw new \DomainException("Linha {$n}: uma linha isenta (IVA 0%) exige o motivo de isenção.");
+        }
+
+        if (! isset(((array) config('faturacao.motivos_isencao', []))[$motivo])) {
+            throw new \DomainException("Linha {$n}: o motivo de isenção '{$motivo}' não consta da tabela (faturacao.motivos_isencao).");
+        }
+
+        // Nada de códigos por confirmar a caminho da AGT.
+        if (config('faturacao.agt.ativo') && ! config('faturacao.motivos_isencao_confirmados')) {
+            throw new \DomainException("Linha {$n}: com a AGT ativa, as isenções só são aceites depois de confirmares a tabela de motivos de isenção (FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS).");
+        }
+    }
 }
diff --git a/Modules/Faturacao/config/config.php b/Modules/Faturacao/config/config.php
index 04af0e6..15c6e1a 100644
--- a/Modules/Faturacao/config/config.php
+++ b/Modules/Faturacao/config/config.php
@@ -78,14 +78,86 @@
     */
     'saft_numero_certificado' => env('FATURACAO_SAFT_NUMERO_CERTIFICADO', '0'),
 
+    /*
+    | Validação do SAF-T contra o XSD OFICIAL da AGT. Coloca o ficheiro .xsd
+    | (que a AGT publica com a especificação) num caminho acessível ao servidor
+    | e aponta FATURACAO_SAFT_XSD para ele. Sem XSD, os ficheiros ficam
+    | marcados como "não validados" e, em produção, não podem ser descarregados.
+    | A estrutura atual do exportador é PROVISÓRIA: só passa a "validada"
+    | quando o XSD oficial a aceitar.
+    */
+    'saft' => [
+        'xsd_path' => env('FATURACAO_SAFT_XSD') ?: null,
+    ],
+
     /*
     | Taxas de IVA aceites na emissão (percentagens, separadas por vírgula no .env).
-    | Confirma contra a tabela atual da AGT antes de produção. Taxa 0 (isenção)
-    | está DESATIVADA por omissão: exige motivo de isenção (códigos M no SAF-T), ainda
-    | por implementar — só a ativar depois disso (ver README).
+    | Confirma contra a tabela atual da AGT antes de produção. A taxa 0 (isenção)
+    | está DESATIVADA por omissão: para a ativar junta-a (FATURACAO_TAXAS_IVA_PERMITIDAS=14,0)
+    | e confirma a tabela 'motivos_isencao' abaixo — cada linha a 0% exige um motivo.
     */
     'taxas_iva_permitidas' => array_map('floatval', array_filter(explode(',', (string) env('FATURACAO_TAXAS_IVA_PERMITIDAS') ?: '14'), 'strlen')),
 
+    /*
+    |--------------------------------------------------------------------------
+    | Motivos de isenção / não sujeição de IVA (taxExemptionCode)
+    |--------------------------------------------------------------------------
+    | Uma linha a 0% só é aceite com um motivo desta tabela, e o código segue
+    | para a AGT em `taxExemptionCode` (3 caracteres, Anexo 6.4 do Decreto
+    | Executivo n.º 683/25). 'tipo': ISE => taxType IVA + taxCode ISE (isento);
+    | NS => taxType NS (não sujeito).
+    |
+    | ATENÇÃO — tabela POR CONFIRMAR: é a transcrição de um terceiro (WISEDAT,
+    | set/2025) da tabela angolana de motivos de isenção; não é a cópia oficial
+    | do Anexo 6.4. Compara-a com o anexo oficial, corrige o que for preciso e só
+    | então define FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS=true. Enquanto isso for
+    | false, com a AGT ativa as isenções são recusadas (nenhum código por
+    | confirmar segue para a AGT).
+    | (M00 — Regime Simplificado — não é um motivo de isenção e fica de fora.)
+    */
+    'motivos_isencao_confirmados' => (bool) env('FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS', false),
+
+    'motivos_isencao' => [
+        'M02' => ['tipo' => 'NS', 'descricao' => 'Transmissão de bens e serviço não sujeita'],
+        'M04' => ['tipo' => 'NS', 'descricao' => 'IVA – Regime de Exclusão (Lei n.º 42/20)'],
+        'M10' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 1 do artigo 12.º do CIVA (bens alimentares)'],
+        'M11' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 1 do artigo 12.º do CIVA (medicamentos)'],
+        'M12' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea c) do n.º 1 do artigo 12.º do CIVA'],
+        'M13' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea d) do n.º 1 do artigo 12.º do CIVA (livros)'],
+        'M14' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea e) do n.º 1 do artigo 12.º do CIVA (locação de imóveis habitacionais)'],
+        'M15' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea f) do n.º 1 do artigo 12.º do CIVA'],
+        'M16' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea g) do n.º 1 do artigo 12.º do CIVA (jogos)'],
+        'M17' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea h) do n.º 1 do artigo 12.º do CIVA (transporte colectivo de passageiros)'],
+        'M18' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea i) do n.º 1 do artigo 12.º do CIVA (intermediação financeira)'],
+        'M19' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea j) do n.º 1 do artigo 12.º do CIVA (seguros)'],
+        'M20' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea k) do n.º 1 do artigo 12.º do CIVA (produtos petrolíferos)'],
+        'M21' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea l) do n.º 1 do artigo 12.º do CIVA (ensino)'],
+        'M22' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea m) do artigo 12.º do CIVA (serviços médico-sanitários)'],
+        'M23' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea n) do artigo 12.º do CIVA (transporte de doentes)'],
+        'M24' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea o) do artigo 12.º do CIVA (equipamentos médicos)'],
+        'M30' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do artigo 15.º do CIVA (exportações)'],
+        'M31' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea b) do artigo 15.º do CIVA'],
+        'M32' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea c) do artigo 15.º do CIVA'],
+        'M33' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea d) do artigo 15.º do CIVA'],
+        'M34' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea e) do artigo 15.º do CIVA'],
+        'M35' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea f) do artigo 15.º do CIVA'],
+        'M36' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea g) do artigo 15.º do CIVA'],
+        'M37' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea h) do artigo 15.º do CIVA'],
+        'M38' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea i) do artigo 15.º do CIVA'],
+        'M80' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 1 do artigo 14.º do CIVA (importações)'],
+        'M81' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea b) do n.º 1 do artigo 14.º do CIVA'],
+        'M82' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea c) do n.º 1 do artigo 14.º do CIVA'],
+        'M83' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea d) do n.º 1 do artigo 14.º do CIVA'],
+        'M84' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea e) do n.º 1 do artigo 14.º do CIVA'],
+        'M85' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 2 do artigo 14.º do CIVA'],
+        'M86' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea b) do n.º 2 do artigo 14.º do CIVA'],
+        'M90' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea a) do n.º 1 do artigo 16.º do CIVA (regimes aduaneiros especiais)'],
+        'M91' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea b) do n.º 1 do artigo 16.º do CIVA'],
+        'M92' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea c) do n.º 1 do artigo 16.º do CIVA'],
+        'M93' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea d) do n.º 1 do artigo 16.º do CIVA'],
+        'M94' => ['tipo' => 'ISE', 'descricao' => 'Isento nos termos da alínea e) do n.º 1 do artigo 16.º do CIVA'],
+    ],
+
     /*
     |--------------------------------------------------------------------------
     | Comunicação com a AGT (Facturação Electrónica)
@@ -119,6 +191,10 @@
         'sem_resposta_horas' => (int) env('FATURACAO_AGT_SEM_RESPOSTA_HORAS', 24), // horas até um "enviado" sem estado final exigir intervenção
         'operation_type_padrao' => env('FATURACAO_AGT_OPERATION_TYPE', 'SG'), // tipo de operação das linhas (SG = serviço geral)
         'unidade_padrao' => 'UN',
+        // Notas de débito não liquidam imposto (Decreto Presidencial 71/25, art. 3.º, al. m):
+        // seguem para a AGT com taxType NS e este taxExemptionCode (Anexo 6.4). Sem valor
+        // configurado e confirmado (motivos_isencao_confirmados), a ND fica em 'erro' e não é enviada.
+        'nd_codigo_nao_sujeicao' => env('FATURACAO_AGT_ND_CODIGO_NS') ?: null,
         // Taxa de IVA (2 casas) => taxCode da AGT (NOR, INT, RED, OUT). Só as listadas são enviadas.
         'codigos_taxa' => ['14.00' => 'NOR'],
         'software' => [
diff --git a/Modules/Faturacao/database/migrations/2026_10_22_000015_add_motivo_isencao_e_correcao_agt.php b/Modules/Faturacao/database/migrations/2026_10_22_000015_add_motivo_isencao_e_correcao_agt.php
new file mode 100644
index 0000000..aebaaa0
--- /dev/null
+++ b/Modules/Faturacao/database/migrations/2026_10_22_000015_add_motivo_isencao_e_correcao_agt.php
@@ -0,0 +1,52 @@
+<?php
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * - motivo_isencao: código do motivo de isenção/não sujeição (taxExemptionCode,
+     *   3 caracteres) das linhas a 0%. Fica gravado na linha — documentos emitidos
+     *   continuam imutáveis (triggers das linhas) e a nota de crédito copia-o da fatura.
+     * - faturas.rejeitado_documento_no: documentNo da fatura rejeitada/inválida que
+     *   este documento corrige (documentStatus "C" + rejectedDocumentNo na AGT; regra E46:
+     *   a correção de um documento rejeitado faz-se com um NOVO número de documento).
+     */
+    public function up(): void
+    {
+        foreach (['fatura_linhas', 'notas_credito_debito_linhas'] as $tabela) {
+            if (! Schema::hasColumn($tabela, 'motivo_isencao')) {
+                Schema::table($tabela, function (Blueprint $table) {
+                    $table->string('motivo_isencao', 3)->nullable();
+                });
+            }
+        }
+
+        if (! Schema::hasColumn('faturas', 'rejeitado_documento_no')) {
+            Schema::table('faturas', function (Blueprint $table) {
+                $table->string('rejeitado_documento_no', 60)->nullable();
+                $table->index(['empresa_id', 'rejeitado_documento_no']);
+            });
+        }
+    }
+
+    public function down(): void
+    {
+        foreach (['fatura_linhas', 'notas_credito_debito_linhas'] as $tabela) {
+            if (Schema::hasColumn($tabela, 'motivo_isencao')) {
+                Schema::table($tabela, function (Blueprint $table) {
+                    $table->dropColumn('motivo_isencao');
+                });
+            }
+        }
+
+        if (Schema::hasColumn('faturas', 'rejeitado_documento_no')) {
+            Schema::table('faturas', function (Blueprint $table) {
+                $table->dropIndex(['empresa_id', 'rejeitado_documento_no']);
+                $table->dropColumn('rejeitado_documento_no');
+            });
+        }
+    }
+};
diff --git a/Modules/Faturacao/docs/CHAVES.md b/Modules/Faturacao/docs/CHAVES.md
new file mode 100644
index 0000000..3ede188
--- /dev/null
+++ b/Modules/Faturacao/docs/CHAVES.md
@@ -0,0 +1,68 @@
+# Chaves de assinatura — gestão, verificação e rotação
+
+Cada documento fiscal é assinado (JWS RS256) com uma chave RSA privada. Este
+documento diz onde ficam as chaves, como se verifica que estão certas e como se
+faz a rotação. Os passos que dependem da AGT (pedir/comunicar/revogar uma chave)
+estão marcados **[confirmar com a AGT]**: segue sempre a página "Gestão de
+Certificados e Chaves" da documentação oficial da Facturação Electrónica.
+
+## Regras
+
+| Chave | Onde | Quem a usa |
+|---|---|---|
+| **Da empresa** (emissor) | `{FATURACAO_CHAVES_DIR}/{NIF}.pem` | assina os documentos dessa empresa |
+| **Do software** (produtor) | `FATURACAO_AGT_CHAVE_SOFTWARE` | assina o `softwareInfo` dos pedidos à AGT |
+| **Genérica** (`FATURACAO_CHAVE_PRIVADA_PATH`) | só desenvolvimento | só é aceite com `APP_ENV` local/testing **e** a AGT desligada |
+
+- Uma chave por empresa. Duas empresas nunca partilham a chave, e a chave do software
+  é diferente das dos emissores.
+- RSA com pelo menos 2048 bits (`faturacao.chave_minima_bits`). Chaves mais fracas são recusadas.
+- Fora de desenvolvimento, se não existir `{NIF}.pem`, a emissão **falha** — nunca recorre à
+  chave genérica. Assinar sem empresa (por exemplo o `softwareInfo`) também falha fora de
+  desenvolvimento se não for pela chave do software.
+- Ficheiros `0600`, pertencentes ao utilizador que corre o PHP; nunca no Git
+  (`storage/app/faturacao/` está no `.gitignore`); cópias de segurança **cifradas e separadas**
+  das cópias da base de dados.
+
+## Verificar
+
+```bash
+php artisan faturacao:verificar-chaves            # todas as empresas
+php artisan faturacao:verificar-chaves --empresa=3
+```
+
+Falha (código de saída 1) se: faltar a chave de uma empresa (com a AGT ativa ou em
+produção), a chave não for RSA ≥ 2048 bits, duas empresas partilharem a chave, a chave
+genérica for igual à de uma empresa, a chave do software faltar ou for igual à de um
+emissor. Avisa se as permissões forem abertas ou se a chave genérica ainda estiver no
+servidor. Corre-o antes de cada deploy, depois de restaurar uma cópia de segurança e
+depois de qualquer rotação.
+
+A auditoria da cadeia usa a chave pública de cada empresa (derivada da respetiva chave
+privada, neste servidor):
+
+```bash
+php artisan faturacao:verificar-cadeia --agt
+php artisan faturacao:verificar-cadeia --empresa=3 --chave-publica=/cofre/5001636863.v1.pub.pem
+```
+
+## Rotação (chave de uma empresa)
+
+1. **[confirmar com a AGT]** Obter a chave nova e comunicar a chave pública, se o processo o exigir.
+2. **Arquivar a chave pública antiga** (a pública não é segredo) em
+   `NIF.v{N}.pub.pem`, num sítio seguro. Sem ela não se verificam os documentos já assinados:
+   cada documento guarda o `chave_versao` com que foi assinado.
+3. Aumentar `FATURACAO_CHAVE_VERSAO` (inteiro sequencial).
+4. Gravar a chave nova em `{NIF}.pem` com `chmod 600`.
+5. `php artisan faturacao:verificar-chaves --empresa=ID` tem de passar.
+6. Em **homologação**: emitir e enviar um documento de teste e confirmar o estado `válido`.
+7. Documentos antigos verificam-se com a chave pública arquivada
+   (`faturacao:verificar-cadeia --empresa=ID --chave-publica=...`).
+
+## Perda ou fuga de uma chave
+
+- **Fuga:** parar a emissão dessa empresa, **[confirmar com a AGT]** a revogação e a chave de
+  substituição, e rodar como acima. Os documentos assinados com a chave exposta continuam
+  na cadeia: não se alteram nem se reassinam.
+- **Perda:** sem a privada não se emite; com a pública arquivada continuam verificáveis os
+  documentos antigos. Pede-se nova chave **[confirmar com a AGT]**.
diff --git a/Modules/Faturacao/docs/HOMOLOGACAO_AGT.md b/Modules/Faturacao/docs/HOMOLOGACAO_AGT.md
new file mode 100644
index 0000000..7c33e38
--- /dev/null
+++ b/Modules/Faturacao/docs/HOMOLOGACAO_AGT.md
@@ -0,0 +1,44 @@
+# Homologação com a AGT — checklist e evidências
+
+Os testes automáticos usam respostas simuladas da AGT (`Http::fake`): provam a lógica
+local, **não** a integração real. A conformidade só se pode afirmar depois de
+executados, no ambiente de **homologação** (`sifphml.minfin.gov.ao`), os cenários abaixo,
+com evidência guardada. Nada nesta lista está dado como feito.
+
+Pré-requisitos: `faturacao:verificar-chaves` a passar; credenciais de produtor;
+software registado na AGT; empresa de teste aderente; séries pedidas
+(`php artisan faturacao:agt-solicitar-serie <empresa> FT|NC|ND|RC`).
+Com a tabela de motivos de isenção ainda por confirmar, as isenções e as notas de
+débito ficam bloqueadas em `agt.ativo` (ver README, "Correções da auditoria").
+
+## Cenários
+
+Para cada linha guarda: data/hora, `documentNo`, `requestID`, estado final e a resposta
+da AGT (captura ou JSON). Corre `php artisan faturacao:agt-sincronizar` para enviar/consultar.
+
+| # | Cenário | Esperado | Evidência |
+|---|---|---|---|
+| 1 | Pedir série de cada tipo (FT, NC, ND, RC) | série criada; numeração `{tipo} {código}/{n}` | |
+| 2 | Estender uma série existente | mesma série, limite maior | |
+| 3 | FT com IVA 14% | `valido` | |
+| 4 | FT com linha isenta (ISE + `taxExemptionCode`) | `valido` — confirma o código na tabela | |
+| 5 | FT com linha não sujeita (NS) | `valido` | |
+| 6 | NC total e NC parcial sobre a FT 3 | `valido`; soma dentro do remanescente (E41/E42) | |
+| 7 | ND (taxType NS, sem imposto) | `valido` — confirma se a AGT aceita o `taxExemptionCode` escolhido | |
+| 8 | RC sobre uma FT | `valido` | |
+| 9 | Documento inválido de propósito (ex.: total incoerente, E21–E24) | `invalido`; erros guardados | |
+| 10 | Correção do 9: `faturacao:agt-corrigir <submissão>`, rever, emitir | novo número, `documentStatus` C + `rejectedDocumentNo`; `valido` | |
+| 11 | Reenviar o mesmo `documentNo` rejeitado | recusado (E46) — confirma que o sistema não o tenta | |
+| 12 | Pedido repetido (mesmo `submissionUUID`) | devolve o `requestID` original, sem duplicar | |
+| 13 | AGT indisponível / timeout a meio de um lote | submissões ficam `pendente`/`erro`, sem duplicados ao retomar | |
+| 14 | `faturacao:verificar-cadeia --agt` | sem problemas; avisos só para o que não estiver `valido` | |
+
+## Antes de produção
+
+- [ ] Todos os cenários acima feitos em homologação, com evidência.
+- [ ] Tabela de motivos de isenção comparada com o Anexo 6.4 oficial e `FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS=true`.
+- [ ] `FATURACAO_AGT_ND_CODIGO_NS` definido com um código de não sujeição confirmado.
+- [ ] Número de certificação do software real em `FATURACAO_SAFT_NUMERO_CERTIFICADO`.
+- [ ] XSD oficial do SAF-T em `FATURACAO_SAFT_XSD` e exportações a passar na validação.
+- [ ] Migrações e testes corridos na base de dados de produção (MySQL), incluindo concorrência de emissões.
+- [ ] Monitorização do `faturacao:agt-sincronizar`, cópias de segurança testadas, `faturacao:verificar-chaves` limpo.
diff --git a/Modules/Faturacao/docs/MATRIZ_REQUISITOS_FISCAIS.md b/Modules/Faturacao/docs/MATRIZ_REQUISITOS_FISCAIS.md
new file mode 100644
index 0000000..debffec
--- /dev/null
+++ b/Modules/Faturacao/docs/MATRIZ_REQUISITOS_FISCAIS.md
@@ -0,0 +1,41 @@
+# Matriz de requisitos fiscais (rascunho a validar)
+
+Cada requisito aponta para o campo/regra no modelo, a validação, o que é assinado, o
+SAF-T e o teste. **Estados:** ✅ implementado · 🟡 parcial · ⛔ em falta · ❔ por confirmar na lei.
+Fonte dos campos AGT: documentação oficial "Registar Factura" (quiosque AGT, Facturação
+Electrónica). Esta matriz tem de ser revista contra o texto legal em vigor (Decreto
+Presidencial 71/25, Decreto Executivo 683/25) antes de se afirmar conformidade.
+
+## Campos do documento enviado à AGT
+
+| Requisito | Campo / regra | Validação | Assinado | SAF-T | Teste | Estado |
+|---|---|---|---|---|---|---|
+| Identificação única do documento (`documentNo`) | `numero_documento` (`NumeracaoService`) | série AGT por estabelecimento, sem lacunas | ✅ | `InvoiceNo` | AgtSerieTest, SerieEstabelecimentoTest | ✅ |
+| Estado N/C e `rejectedDocumentNo` | `faturas.rejeitado_documento_no` | só correção de inválidas/rejeitadas | — | ⛔ | CorrecaoDocumentoRejeitadoTest | 🟡 só faturas |
+| Assinatura do documento | `hash` (JWS RS256) | chave da empresa, RSA ≥ 2048 | — | `Hash` (>172 car.) | AssinaturaJwsTest, ChavesVerificacaoTest | 🟡 |
+| Data, tipo, NIF/país/nome do cliente | campos congelados na emissão | cliente da própria empresa | ✅ | sim | ClienteCongeladoTest | ✅ |
+| `systemEntryDate` | `data_hora_sistema` | — | — | `SystemEntryDate` | CadeiaFiscalTest | ✅ |
+| Linhas: número, produto, descrição, quantidade, unidade, preço | `*_linhas` | `ValidacaoLinhas` | — | `Line` | ValidacaoLinhasTest | ✅ |
+| Descontos (`unitPrice` ≠ `unitPriceBase`, `settlementAmount`) | — (preço = base; desconto 0) | — | — | — | — | ⛔ |
+| `operationType` por linha | `SG` fixo (config) | — | — | — | AgtSubmissaoTest | 🟡 |
+| `operationDate` (obrigatório em FG/GF) | — | — | — | — | — | ⛔ (tipos não suportados) |
+| IVA por linha (`taxes`) | taxa → código (`agt.codigos_taxa`) | taxa permitida + código AGT | — | `Tax` / `TaxTable` | NotaDebitoEIsencaoAgtTest, SafTEstruturaEVersoesTest | 🟡 só 14% mapeado |
+| Isenção / não sujeição (`taxExemptionCode`) | `motivo_isencao` (tabela `motivos_isencao`) | motivo da tabela; tabela por confirmar | — | `TaxExemptionCode` | IsencaoIvaTest | 🟡 tabela por confirmar |
+| Nota de débito sem imposto | taxa 0, NS + `nd_codigo_nao_sujeicao` | recusa IVA | — | NS | NotaDebitoSemIvaTest | 🟡 código por confirmar |
+| Referência da NC à fatura (`referenceInfo`) | `fatura_id` obrigatório | origem da empresa; limite por taxa | — | — | RegrasDeNegocioTest, LimiteCreditoPorTaxaTest | ✅ |
+| Totais (`documentTotals`) | `valor_*` | IVA por excesso por linha | ✅ | `DocumentTotals` | DinheiroValidacaoTest | ✅ |
+| Divisa / exportação (`currency`) | — | recusa não-AOA | — | — | — | ⛔ |
+| Retenções na fonte (`withholdingTaxList`) | — | — | — | — | — | ⛔ |
+| Recibos (`paymentReceipt`) | `Recibo` + fatura | saldo = total − recibos − NC | — | ⛔ (não exportados) | EmissaoSegurancaTest, LimiteCreditoPorTaxaTest | 🟡 só com fatura |
+| Recibos de adiantamento / `FA` | — | recusa com AGT ativa | — | — | AgtSubmissaoTest | ⛔ |
+| Tipos FR, FA, FG, GF, AC, AR, TV, RG, RE, AF, RP, RA, CS, LD | — | — | — | — | — | ⛔ |
+| QR Code | `QrCodeAgt` | — | — | — | QrCodeAgtTest | ✅ |
+
+## Requisitos do Regime Jurídico das Facturas ainda por mapear
+
+A auditoria apontou como não demonstrados (não como infrações): data da operação quando
+diferente da emissão; local de disponibilização dos bens ou da prestação dos serviços;
+motivo e fundamento legal da isenção na própria fatura impressa; descontos e a sua
+distribuição fiscal; menções específicas por tipo de documento. ❔ Para cada um: ler o
+artigo aplicável, acrescentar uma linha a esta matriz com campo, validação, elemento
+assinado, elemento SAF-T e teste, e só então marcar ✅.
diff --git a/Modules/Faturacao/resources/views/faturas/criar.blade.php b/Modules/Faturacao/resources/views/faturas/criar.blade.php
index 3e1f8cf..15b73ad 100644
--- a/Modules/Faturacao/resources/views/faturas/criar.blade.php
+++ b/Modules/Faturacao/resources/views/faturas/criar.blade.php
@@ -53,6 +53,17 @@ class="col-span-4 sm:col-span-2 rounded-md border border-slate-300 px-2 py-1.5 t
                             <input type="number" step="0.01" min="0" max="100" :name="`linhas[${indice}][taxa_iva]`" x-model.number="linha.taxa_iva" placeholder="IVA %"
                                    class="col-span-3 sm:col-span-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                             <button type="button" @click="removerLinha(indice)" class="col-span-1 text-red-500 hover:text-red-700" title="Remover">✕</button>
+                            @if ($aceitaIsencao)
+                                {{-- Linhas a 0% exigem o motivo de isenção; desativado (não enviado) nas restantes. --}}
+                                <select :name="`linhas[${indice}][motivo_isencao]`" x-model="linha.motivo_isencao"
+                                        x-show="Number(linha.taxa_iva) === 0" :disabled="Number(linha.taxa_iva) !== 0" :required="Number(linha.taxa_iva) === 0"
+                                        class="col-span-12 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
+                                    <option value="">Motivo de isenção...</option>
+                                    @foreach ($motivosIsencao as $codigo => $rotulo)
+                                        <option value="{{ $codigo }}">{{ $rotulo }}</option>
+                                    @endforeach
+                                </select>
+                            @endif
                         </div>
                     </template>
                 </div>
@@ -79,9 +90,9 @@ class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-whit
     <script>
         function novaFatura(taxaIvaGeral) {
             return {
-                linhas: [{ descricao: '', quantidade: 1, preco_unitario: 0, taxa_iva: taxaIvaGeral }],
+                linhas: [{ descricao: '', quantidade: 1, preco_unitario: 0, taxa_iva: taxaIvaGeral, motivo_isencao: '' }],
                 adicionarLinha() {
-                    this.linhas.push({ descricao: '', quantidade: 1, preco_unitario: 0, taxa_iva: taxaIvaGeral });
+                    this.linhas.push({ descricao: '', quantidade: 1, preco_unitario: 0, taxa_iva: taxaIvaGeral, motivo_isencao: '' });
                 },
                 removerLinha(indice) {
                     this.linhas.splice(indice, 1);
diff --git a/Modules/Faturacao/resources/views/saft/exportar.blade.php b/Modules/Faturacao/resources/views/saft/exportar.blade.php
index 41cdfff..cf525b3 100644
--- a/Modules/Faturacao/resources/views/saft/exportar.blade.php
+++ b/Modules/Faturacao/resources/views/saft/exportar.blade.php
@@ -42,11 +42,23 @@ class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
 
         <h2 class="mt-8 text-sm font-semibold text-slate-900">Ficheiros gerados</h2>
         @forelse ($ficheiros as $f)
-            <div class="mt-2 flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 text-sm">
-                <span class="text-slate-700">{{ $f['nome'] }}
-                    <span class="text-xs text-slate-400">· {{ number_format($f['tamanho'] / 1024, 1) }} KB · {{ $f['modificado_em']->format('d/m/Y H:i') }}</span>
-                </span>
-                <a href="{{ route('faturacao.saft.descarregar', $f['nome']) }}" class="font-medium text-indigo-600 hover:text-indigo-500">Descarregar</a>
+            <div class="mt-2 rounded-md border border-slate-200 px-3 py-2 text-sm">
+                <div class="flex items-center justify-between">
+                    <span class="text-slate-700">{{ $f['nome'] }}
+                        <span class="text-xs text-slate-400">· {{ number_format($f['tamanho'] / 1024, 1) }} KB · {{ $f['modificado_em']->format('d/m/Y H:i') }}</span>
+                    </span>
+                    @if ($f['descarregavel'])
+                        <a href="{{ route('faturacao.saft.descarregar', $f['nome']) }}" class="font-medium text-indigo-600 hover:text-indigo-500">Descarregar</a>
+                    @else
+                        <span class="text-xs font-medium text-red-600">Indisponível</span>
+                    @endif
+                </div>
+                <div class="mt-1 text-xs {{ $f['estado'] === 'valido_xsd' ? 'text-emerald-700' : 'text-amber-700' }}">
+                    {{ $f['rotulo'] }}@if ($f['sha256']) <span class="text-slate-400">· SHA-256 {{ substr($f['sha256'], 0, 12) }}…</span>@endif
+                </div>
+                @foreach (array_slice($f['erros'], 0, 3) as $erro)
+                    <div class="mt-1 text-xs text-red-600">{{ $erro }}</div>
+                @endforeach
             </div>
         @empty
             <p class="mt-2 text-sm text-slate-400">Ainda não há ficheiros gerados.</p>
diff --git a/Modules/Faturacao/tests/Feature/ChavesVerificacaoTest.php b/Modules/Faturacao/tests/Feature/ChavesVerificacaoTest.php
new file mode 100644
index 0000000..6fa8597
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/ChavesVerificacaoTest.php
@@ -0,0 +1,214 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Artisan;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
+use Modules\Faturacao\Services\AssinaturaFiscalService;
+use Modules\Faturacao\Support\Jws;
+use Tests\TestCase;
+
+/** Chaves de assinatura: uma por empresa, nunca a genérica fora de desenvolvimento, e auditáveis. */
+class ChavesVerificacaoTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected string $dir;
+
+    protected string $generica;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->dir = sys_get_temp_dir().'/chaves-'.uniqid();
+        mkdir($this->dir);
+        $this->generica = $this->dir.'/generica.pem';
+        $this->gerarChave($this->generica);
+
+        $this->beforeApplicationDestroyed(function () {
+            foreach (glob($this->dir.'/*') ?: [] as $ficheiro) {
+                @unlink($ficheiro);
+            }
+            @rmdir($this->dir);
+        });
+
+        config([
+            'faturacao.chaves_dir' => $this->dir,
+            'faturacao.chave_privada_path' => $this->generica,
+            'faturacao.agt.ativo' => false,
+        ]);
+    }
+
+    protected function gerarChave(string $caminho, int $bits = 2048): string
+    {
+        $recurso = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
+        openssl_pkey_export($recurso, $pem);
+        file_put_contents($caminho, $pem);
+        chmod($caminho, 0600);
+
+        return $caminho;
+    }
+
+    protected function empresa(string $nif): Empresa
+    {
+        return Empresa::create(['nome_comercial' => "Empresa {$nif}", 'nif' => $nif]);
+    }
+
+    /** @return array{0: int, 1: string} */
+    protected function auditar(array $opcoes = []): array
+    {
+        $codigo = Artisan::call('faturacao:verificar-chaves', $opcoes);
+
+        return [$codigo, Artisan::output()];
+    }
+
+    public function test_assinar_sem_empresa_e_recusado_em_producao(): void
+    {
+        $this->app->detectEnvironment(fn () => 'production');
+
+        $this->expectException(AssinaturaFiscalException::class);
+
+        app(AssinaturaFiscalService::class)->assinar('{"a":1}');
+    }
+
+    public function test_assinar_sem_empresa_e_recusado_com_a_agt_ativa(): void
+    {
+        config(['faturacao.agt.ativo' => true]);
+
+        $this->expectException(AssinaturaFiscalException::class);
+
+        app(AssinaturaFiscalService::class)->assinar('{"a":1}');
+    }
+
+    public function test_assinar_sem_empresa_continua_a_funcionar_em_testes(): void
+    {
+        $jws = app(AssinaturaFiscalService::class)->assinar('{"a":1}');
+
+        $this->assertSame('{"a":1}', Jws::payload($jws));
+    }
+
+    public function test_chave_publica_da_empresa_verifica_o_que_a_empresa_assinou(): void
+    {
+        $empresa = $this->empresa('5001636863');
+        $this->gerarChave($this->dir.'/5001636863.pem');
+
+        $servico = app(AssinaturaFiscalService::class);
+        $jws = $servico->assinar('{"a":1}', $empresa->id);
+
+        $this->assertTrue($servico->verificarComPem('{"a":1}', $jws, (string) $servico->chavePublicaDaEmpresa($empresa->id)));
+        $this->assertFalse($servico->verificarComPem('{"a":2}', $jws, (string) $servico->chavePublicaDaEmpresa($empresa->id)));
+    }
+
+    public function test_chave_publica_da_empresa_nunca_cai_na_chave_generica(): void
+    {
+        $empresa = $this->empresa('5001636863'); // sem ficheiro {NIF}.pem
+
+        $this->assertNull(app(AssinaturaFiscalService::class)->chavePublicaDaEmpresa($empresa->id));
+    }
+
+    public function test_verificacao_passa_com_uma_chave_por_empresa(): void
+    {
+        $this->empresa('5001636863');
+        $this->empresa('5001636864');
+        $this->gerarChave($this->dir.'/5001636863.pem');
+        $this->gerarChave($this->dir.'/5001636864.pem');
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(0, $codigo, $saida);
+        $this->assertStringContainsString('2 chave(s)', $saida);
+    }
+
+    public function test_empresa_sem_chave_e_aviso_em_desenvolvimento_e_problema_com_a_agt_ativa(): void
+    {
+        $this->empresa('5001636863');
+
+        [$codigo, $saida] = $this->auditar();
+        $this->assertSame(0, $codigo, $saida);
+        $this->assertStringContainsString('chave em falta', $saida);
+
+        config(['faturacao.agt.ativo' => true, 'faturacao.agt.software.chave_privada_path' => $this->gerarChave($this->dir.'/software.pem')]);
+
+        [$codigo, $saida] = $this->auditar();
+        $this->assertSame(1, $codigo, $saida);
+        $this->assertStringContainsString('chave em falta', $saida);
+    }
+
+    public function test_duas_empresas_com_a_mesma_chave_falham(): void
+    {
+        $this->empresa('5001636863');
+        $this->empresa('5001636864');
+        $this->gerarChave($this->dir.'/5001636863.pem');
+        copy($this->dir.'/5001636863.pem', $this->dir.'/5001636864.pem');
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(1, $codigo);
+        $this->assertStringContainsString('mesma chave', $saida);
+    }
+
+    public function test_empresa_com_a_chave_generica_falha(): void
+    {
+        $this->empresa('5001636863');
+        copy($this->generica, $this->dir.'/5001636863.pem');
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(1, $codigo);
+        $this->assertStringContainsString('chave genérica', $saida);
+    }
+
+    public function test_chave_com_menos_de_2048_bits_falha(): void
+    {
+        $this->empresa('5001636863');
+        $this->gerarChave($this->dir.'/5001636863.pem', 1024);
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(1, $codigo);
+        $this->assertStringContainsString('1024 bits', $saida);
+    }
+
+    public function test_com_a_agt_ativa_a_chave_do_software_tem_de_ser_distinta_das_dos_emissores(): void
+    {
+        $this->empresa('5001636863');
+        $this->gerarChave($this->dir.'/5001636863.pem');
+        copy($this->dir.'/5001636863.pem', $this->dir.'/software.pem');
+        config(['faturacao.agt.ativo' => true, 'faturacao.agt.software.chave_privada_path' => $this->dir.'/software.pem']);
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(1, $codigo);
+        $this->assertStringContainsString('chave do software', $saida);
+    }
+
+    public function test_permissoes_abertas_no_ficheiro_da_chave_geram_aviso(): void
+    {
+        if (DIRECTORY_SEPARATOR !== '/') {
+            $this->markTestSkipped('Permissões POSIX.');
+        }
+
+        $this->empresa('5001636863');
+        chmod($this->gerarChave($this->dir.'/5001636863.pem'), 0644);
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(0, $codigo, $saida);
+        $this->assertStringContainsString('chmod 600', $saida);
+    }
+
+    public function test_opcao_empresa_limita_a_verificacao(): void
+    {
+        $a = $this->empresa('5001636863');
+        $this->empresa('5001636864'); // sem chave: ficaria como aviso, mas fora do âmbito
+        $this->gerarChave($this->dir.'/5001636863.pem');
+
+        [$codigo, $saida] = $this->auditar(['--empresa' => $a->id]);
+
+        $this->assertSame(0, $codigo, $saida);
+        $this->assertStringNotContainsString('chave em falta', $saida);
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/CorrecaoDocumentoRejeitadoTest.php b/Modules/Faturacao/tests/Feature/CorrecaoDocumentoRejeitadoTest.php
new file mode 100644
index 0000000..35596f8
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/CorrecaoDocumentoRejeitadoTest.php
@@ -0,0 +1,225 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Http\Client\Request;
+use Illuminate\Support\Facades\Artisan;
+use Illuminate\Support\Facades\Http;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Models\AgtSubmissao;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Services\AgtCorrecaoService;
+use Modules\Faturacao\Services\AgtDocumentoBuilder;
+use Modules\Faturacao\Services\AgtSerieService;
+use Modules\Faturacao\Services\AgtSubmissaoService;
+use Modules\Faturacao\Services\FaturaService;
+use Modules\Faturacao\Support\Jws;
+use Tests\TestCase;
+
+/**
+ * Fatura inválida/rejeitada pela AGT: a correção é um documento NOVO (regra E46),
+ * enviado com documentStatus "C" e rejectedDocumentNo.
+ */
+class CorrecaoDocumentoRejeitadoTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
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
+                'seriesFEResult' => [
+                    'seriesCode' => $r['documentType'].'6325S2C',
+                    'authorizedQuantity' => '1000',
+                    'firstDocumentNo' => '1',
+                    'lastDocumentNo' => '1000',
+                ],
+            ], 200),
+            '*registarFactura' => Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
+        ]);
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);
+
+        foreach (['FT', 'NC'] as $tipo) {
+            app(AgtSerieService::class)->solicitar($this->empresa, $tipo, now()->year);
+        }
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
+    protected function emitirFatura(): Fatura
+    {
+        $faturas = app(FaturaService::class);
+
+        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Serviço A', 'quantidade' => 2, 'preco_unitario' => '500.00', 'taxa_iva' => 14],
+            ['descricao' => 'Serviço B', 'quantidade' => 1, 'preco_unitario' => '250.00', 'taxa_iva' => 14],
+        ], 'Observação original'));
+    }
+
+    protected function submissaoRejeitada(Fatura $fatura, string $estado = AgtSubmissao::INVALIDO): AgtSubmissao
+    {
+        $submissao = AgtSubmissao::query()->where('documento_id', $fatura->id)->firstOrFail();
+        $submissao->update(['estado' => $estado, 'erros' => [['codigo' => 'E21', 'descricao' => 'Valor incoerente']]]);
+
+        return $submissao->fresh();
+    }
+
+    public function test_cria_o_rascunho_de_correcao_com_as_linhas_da_fatura_rejeitada(): void
+    {
+        $original = $this->emitirFatura();
+        $submissao = $this->submissaoRejeitada($original);
+
+        $rascunho = app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($submissao);
+
+        $this->assertSame('rascunho', $rascunho->estado);
+        $this->assertNotSame($original->id, $rascunho->id);
+        $this->assertSame($original->numero_documento, $rascunho->rejeitado_documento_no);
+        $this->assertSame($original->cliente_id, $rascunho->cliente_id);
+        $this->assertSame('Observação original', $rascunho->observacoes);
+        $this->assertSame($original->valor_total, $rascunho->valor_total);
+        $this->assertSame(
+            $original->linhas->map->only(['descricao', 'quantidade', 'preco_unitario', 'taxa_iva'])->all(),
+            $rascunho->linhas->map->only(['descricao', 'quantidade', 'preco_unitario', 'taxa_iva'])->all(),
+        );
+
+        // A fatura rejeitada fica intacta na cadeia fiscal.
+        $this->assertSame('emitida', $original->fresh()->estado);
+    }
+
+    public function test_e_idempotente(): void
+    {
+        $original = $this->emitirFatura();
+        $submissao = $this->submissaoRejeitada($original, AgtSubmissao::REJEITADO);
+        $servico = app(AgtCorrecaoService::class);
+
+        $primeira = $servico->criarRascunhoDeCorrecao($submissao);
+        $segunda = $servico->criarRascunhoDeCorrecao($submissao);
+
+        $this->assertSame($primeira->id, $segunda->id);
+        $this->assertSame(2, Fatura::withoutGlobalScopes()->count());
+    }
+
+    public function test_a_correcao_emitida_tem_numero_novo_e_segue_como_documentstatus_c(): void
+    {
+        $original = $this->emitirFatura();
+        $rascunho = app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($this->submissaoRejeitada($original));
+
+        $correcao = app(FaturaService::class)->emitir($rascunho);
+
+        $this->assertNotSame($original->numero_documento, $correcao->numero_documento);
+
+        $documento = json_decode(Jws::canonico(app(AgtDocumentoBuilder::class)->construir($correcao)), true);
+
+        $this->assertSame('C', $documento['documentStatus']);
+        $this->assertSame($original->numero_documento, $documento['rejectedDocumentNo']);
+        $this->assertSame($correcao->numero_documento, $documento['documentNo']);
+
+        // Uma fatura normal continua com documentStatus N e sem rejectedDocumentNo.
+        $normal = json_decode(Jws::canonico(app(AgtDocumentoBuilder::class)->construir($original->fresh())), true);
+        $this->assertSame('N', $normal['documentStatus']);
+        $this->assertArrayNotHasKey('rejectedDocumentNo', $normal);
+    }
+
+    public function test_o_envio_leva_documentstatus_c_e_o_numero_rejeitado(): void
+    {
+        $original = $this->emitirFatura();
+        $rascunho = app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($this->submissaoRejeitada($original));
+        $correcao = app(FaturaService::class)->emitir($rascunho);
+
+        app(AgtSubmissaoService::class)->enviarPendentes();
+
+        $doc = collect(Http::recorded())
+            ->map(fn ($par) => $par[0])
+            ->filter(fn (Request $r) => str_contains($r->url(), 'registarFactura'))
+            ->flatMap(fn (Request $r) => $r->data()['documents'] ?? [])
+            ->firstWhere('documentNo', $correcao->numero_documento);
+
+        $this->assertNotNull($doc, 'A correção devia ter seguido para a AGT.');
+        $this->assertSame('C', $doc['documentStatus']);
+        $this->assertSame($original->numero_documento, $doc['rejectedDocumentNo']);
+    }
+
+    public function test_so_documentos_invalidos_ou_rejeitados_podem_ser_corrigidos(): void
+    {
+        $original = $this->emitirFatura();
+        $submissao = AgtSubmissao::query()->where('documento_id', $original->id)->firstOrFail();
+
+        foreach ([AgtSubmissao::PENDENTE, AgtSubmissao::ENVIADO, AgtSubmissao::VALIDO, AgtSubmissao::ERRO, AgtSubmissao::AGUARDA_INTERVENCAO] as $estado) {
+            $submissao->update(['estado' => $estado]);
+
+            try {
+                app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($submissao->fresh());
+                $this->fail("A correção de '{$estado}' devia ser recusada.");
+            } catch (\DomainException $e) {
+                $this->assertStringContainsString('inválidos ou rejeitados', $e->getMessage());
+            }
+        }
+
+        $this->assertSame(1, Fatura::withoutGlobalScopes()->count());
+    }
+
+    public function test_outros_tipos_de_documento_nao_sao_corrigidos_automaticamente(): void
+    {
+        $original = $this->emitirFatura();
+        $submissao = $this->submissaoRejeitada($original);
+        $submissao->update(['documento_type' => 'Modules\\Faturacao\\Models\\NotaCreditoDebito']);
+
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('só existe para faturas');
+
+        app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($submissao->fresh());
+    }
+
+    public function test_comando_cria_o_rascunho_e_recusa_submissoes_inexistentes(): void
+    {
+        $original = $this->emitirFatura();
+        $submissao = $this->submissaoRejeitada($original);
+
+        $this->assertSame(0, Artisan::call('faturacao:agt-corrigir', ['submissao' => $submissao->id]));
+        $this->assertStringContainsString('documentStatus C', Artisan::output());
+        $this->assertSame(1, Fatura::withoutGlobalScopes()->whereNotNull('rejeitado_documento_no')->count());
+
+        $this->assertSame(1, Artisan::call('faturacao:agt-corrigir', ['submissao' => 99999]));
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/FormularioFaturaIsencaoTest.php b/Modules/Faturacao/tests/Feature/FormularioFaturaIsencaoTest.php
new file mode 100644
index 0000000..c618056
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/FormularioFaturaIsencaoTest.php
@@ -0,0 +1,108 @@
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
+use Spatie\Permission\Models\Role;
+use Spatie\Permission\PermissionRegistrar;
+use Tests\TestCase;
+
+/** O formulário de nova fatura: isenções com motivo e erros de regra de negócio sem páginas 500. */
+class FormularioFaturaIsencaoTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected User $admin;
+
+    protected Cliente $cliente;
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
+            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
+        ]);
+
+        $empresa = Empresa::create(['nome_comercial' => 'Loja Formulário', 'nif' => '5001636863']);
+        $empresa->aderirServicos([Servico::Faturacao]);
+        $this->cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);
+
+        $this->admin = User::create([
+            'empresa_id' => $empresa->id,
+            'name' => 'Administrador',
+            'email' => 'admin@formulario.ao',
+            'password' => 'Senha-Forte-123!',
+        ]);
+        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
+        $this->admin->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $empresa->id]));
+    }
+
+    /** @return array<string, mixed> */
+    protected function pedido(array $linha): array
+    {
+        return [
+            'cliente_id' => $this->cliente->id,
+            'linhas' => [array_merge(['descricao' => 'Livro', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0], $linha)],
+        ];
+    }
+
+    public function test_guarda_uma_linha_isenta_com_motivo(): void
+    {
+        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['motivo_isencao' => 'M13']))
+            ->assertSessionHasNoErrors()
+            ->assertRedirect();
+
+        $fatura = Fatura::withoutGlobalScopes()->firstOrFail();
+        $this->assertSame('M13', $fatura->linhas()->first()->motivo_isencao);
+        $this->assertSame('0.00', $fatura->valor_iva);
+    }
+
+    public function test_linha_isenta_sem_motivo_volta_ao_formulario_com_a_mensagem(): void
+    {
+        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido([]))
+            ->assertStatus(302)
+            ->assertSessionHasErrors(['linhas']);
+
+        $this->assertStringContainsString('exige o motivo de isenção', session('errors')->first('linhas'));
+        $this->assertSame(0, Fatura::withoutGlobalScopes()->count());
+    }
+
+    public function test_motivo_numa_linha_com_iva_e_recusado_com_mensagem(): void
+    {
+        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['taxa_iva' => 14, 'motivo_isencao' => 'M13']))
+            ->assertSessionHasErrors(['linhas']);
+
+        $this->assertStringContainsString('só as linhas isentas', session('errors')->first('linhas'));
+    }
+
+    public function test_motivo_fora_da_tabela_e_recusado_na_validacao(): void
+    {
+        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['motivo_isencao' => 'ZZZ']))
+            ->assertSessionHasErrors(['linhas.0.motivo_isencao']);
+    }
+
+    public function test_fatura_normal_a_14_continua_a_funcionar_sem_motivo(): void
+    {
+        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['taxa_iva' => 14]))
+            ->assertSessionHasNoErrors()
+            ->assertRedirect();
+
+        $this->assertSame('114.00', Fatura::withoutGlobalScopes()->firstOrFail()->valor_total);
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/IsencaoIvaTest.php b/Modules/Faturacao/tests/Feature/IsencaoIvaTest.php
new file mode 100644
index 0000000..fc4ed7a
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/IsencaoIvaTest.php
@@ -0,0 +1,136 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Services\FaturaService;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Tests\TestCase;
+
+/** Linhas a 0%: só com motivo de isenção da tabela (taxExemptionCode, Anexo 6.4). */
+class IsencaoIvaTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
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
+        config([
+            'faturacao.chave_privada_path' => $caminho,
+            'faturacao.agt.ativo' => false,
+            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
+        ]);
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Isenta', 'nif' => '5001636863']);
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
+    }
+
+    /** @return array<int, array<string, mixed>> */
+    protected function linha(array $extra = []): array
+    {
+        return [array_merge(['descricao' => 'Livro', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0, 'motivo_isencao' => 'M13'], $extra)];
+    }
+
+    public function test_linha_isenta_sem_motivo_e_recusada(): void
+    {
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('exige o motivo de isenção');
+
+        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['motivo_isencao' => null]));
+    }
+
+    public function test_motivo_desconhecido_e_recusado(): void
+    {
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('não consta da tabela');
+
+        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['motivo_isencao' => 'ZZZ']));
+    }
+
+    public function test_motivo_numa_linha_com_iva_e_recusado(): void
+    {
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('só as linhas isentas');
+
+        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['taxa_iva' => 14]));
+    }
+
+    public function test_taxa_omitida_ou_vazia_nunca_se_torna_isencao_em_silencio(): void
+    {
+        foreach ([['taxa_iva' => ''], ['taxa_iva' => null]] as $extra) {
+            try {
+                app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha($extra));
+                $this->fail('Devia ter recusado a linha sem taxa.');
+            } catch (\InvalidArgumentException $e) {
+                $this->assertStringContainsString('taxa de IVA', $e->getMessage());
+            }
+        }
+    }
+
+    public function test_preco_em_falta_ou_vazio_nao_vira_zero(): void
+    {
+        $this->expectException(\InvalidArgumentException::class);
+        $this->expectExceptionMessage('preço unitário');
+
+        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['preco_unitario' => '']));
+    }
+
+    public function test_isencao_valida_e_gravada_com_o_motivo_normalizado(): void
+    {
+        $fatura = app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['motivo_isencao' => ' m13 ']));
+
+        $linha = $fatura->linhas()->first();
+
+        $this->assertSame('M13', $linha->motivo_isencao);
+        $this->assertSame('0.00', $fatura->valor_iva);
+        $this->assertSame('100.00', $fatura->valor_total);
+    }
+
+    public function test_nota_de_credito_de_anulacao_copia_o_motivo_de_isencao(): void
+    {
+        $faturas = app(FaturaService::class);
+        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linha()));
+
+        $notas = app(NotaCreditoDebitoService::class);
+        $nota = $notas->emitir($notas->criarParaAnularFatura($fatura, 'Erro de faturação'));
+
+        $this->assertSame('emitida', $nota->estado);
+        $this->assertSame('M13', $nota->linhas()->first()->motivo_isencao);
+    }
+
+    public function test_nota_de_credito_isenta_sem_motivo_e_recusada(): void
+    {
+        $faturas = app(FaturaService::class);
+        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linha()));
+
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('exige o motivo de isenção');
+
+        app(NotaCreditoDebitoService::class)->criarRascunho(
+            $this->empresa->id, $this->cliente->id, 'credito', 'Devolução', $this->linha(['motivo_isencao' => null]), $fatura->id,
+        );
+    }
+
+    public function test_com_agt_ativa_a_isencao_so_e_aceite_com_a_tabela_confirmada(): void
+    {
+        config(['faturacao.agt.ativo' => true, 'faturacao.motivos_isencao_confirmados' => false]);
+
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS');
+
+        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha());
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/LimiteCreditoPorTaxaTest.php b/Modules/Faturacao/tests/Feature/LimiteCreditoPorTaxaTest.php
new file mode 100644
index 0000000..2e7cd71
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/LimiteCreditoPorTaxaTest.php
@@ -0,0 +1,146 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\NotaCreditoDebito;
+use Modules\Faturacao\Services\FaturaService;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Modules\Faturacao\Services\ReciboService;
+use Tests\TestCase;
+
+/**
+ * Notas de crédito: o valor creditado sem IVA nunca excede o da fatura, taxa a taxa
+ * (a AGT valida o mesmo — regras E41/E42) e continua a interagir bem com os recibos.
+ */
+class LimiteCreditoPorTaxaTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
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
+        config([
+            'faturacao.chave_privada_path' => $caminho,
+            'faturacao.agt.ativo' => false,
+            'faturacao.taxas_iva_permitidas' => [14.0, 7.0],
+        ]);
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Taxas', 'nif' => '5001636863']);
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
+    }
+
+    /** @return array<string, mixed> */
+    protected function linha(float|int $preco, int $taxa): array
+    {
+        return ['descricao' => "Serviço {$taxa}%", 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => $taxa];
+    }
+
+    /** Fatura com 1000 a 14% (IVA 140) e 1000 a 7% (IVA 70): total 2210. */
+    protected function faturaDeDuasTaxas(): Fatura
+    {
+        $servico = app(FaturaService::class);
+
+        return $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [$this->linha(1000, 14), $this->linha(1000, 7)]));
+    }
+
+    protected function rascunhoDeCredito(Fatura $fatura, array $linhas): NotaCreditoDebito
+    {
+        return app(NotaCreditoDebitoService::class)->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', 'Devolução', $linhas, $fatura->id);
+    }
+
+    public function test_credito_a_taxa_que_a_fatura_nao_tem_e_recusado(): void
+    {
+        $servico = app(FaturaService::class);
+        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [$this->linha(1000, 14)]));
+
+        // 500 a 7% cabe no total da fatura (1140), mas a fatura não tem linhas a 7%.
+        $rascunho = $this->rascunhoDeCredito($fatura, [$this->linha(500, 7)]);
+
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('taxa de 7.00%');
+
+        app(NotaCreditoDebitoService::class)->emitir($rascunho);
+    }
+
+    public function test_credito_acima_da_base_de_uma_taxa_e_recusado_mesmo_cabendo_no_total(): void
+    {
+        $fatura = $this->faturaDeDuasTaxas();
+
+        // 1500 a 14%: o total (1710) cabe nos 2210 da fatura, mas a base a 14% é só 1000.
+        $rascunho = $this->rascunhoDeCredito($fatura, [$this->linha(1500, 14)]);
+
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('taxa de 14.00%');
+
+        app(NotaCreditoDebitoService::class)->emitir($rascunho);
+    }
+
+    public function test_creditos_parciais_cumulativos_dentro_da_base_sao_aceites_e_o_excesso_nao(): void
+    {
+        $fatura = $this->faturaDeDuasTaxas();
+        $notas = app(NotaCreditoDebitoService::class);
+
+        $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(600, 14), $this->linha(500, 7)]));
+        $segunda = $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(400, 14), $this->linha(500, 7)]));
+
+        $this->assertSame('emitida', $segunda->estado); // 1000 a 14% e 1000 a 7%: a base toda, nada a mais
+
+        $terceira = $this->rascunhoDeCredito($fatura, [$this->linha(1, 14)]);
+
+        $this->expectException(\DomainException::class);
+
+        $notas->emitir($terceira);
+    }
+
+    public function test_recibo_antes_da_nota_de_credito_continua_a_respeitar_o_saldo(): void
+    {
+        $servico = app(FaturaService::class);
+        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [$this->linha(1000, 14)])); // 1140
+        $recibos = app(ReciboService::class);
+        $notas = app(NotaCreditoDebitoService::class);
+
+        $recibos->emitir($this->empresa->id, $this->cliente->id, '300.00', 'dinheiro', $fatura->id);
+        $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(500, 14)])); // crédito de 570
+
+        // Saldo: 1140 - 300 (recibo) - 570 (nota) = 270.
+        $ultimo = $recibos->emitir($this->empresa->id, $this->cliente->id, '270.00', 'transferencia', $fatura->id);
+        $this->assertSame('emitido', $ultimo->estado);
+
+        $this->expectException(\DomainException::class);
+
+        $recibos->emitir($this->empresa->id, $this->cliente->id, '0.01', 'dinheiro', $fatura->id);
+    }
+
+    public function test_duas_notas_parciais_reduzem_o_saldo_aceite_pelos_recibos(): void
+    {
+        $servico = app(FaturaService::class);
+        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [$this->linha(1000, 14)])); // 1140
+        $notas = app(NotaCreditoDebitoService::class);
+        $recibos = app(ReciboService::class);
+
+        $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(200, 14)])); // 228
+        $notas->emitir($this->rascunhoDeCredito($fatura, [$this->linha(300, 14)])); // 342
+
+        // Saldo: 1140 - 228 - 342 = 570.
+        $this->assertSame('emitido', $recibos->emitir($this->empresa->id, $this->cliente->id, '570.00', 'dinheiro', $fatura->id)->estado);
+
+        $this->expectException(\DomainException::class);
+
+        $recibos->emitir($this->empresa->id, $this->cliente->id, '0.01', 'dinheiro', $fatura->id);
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/NotaDebitoEIsencaoAgtTest.php b/Modules/Faturacao/tests/Feature/NotaDebitoEIsencaoAgtTest.php
new file mode 100644
index 0000000..b8cbe6a
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/NotaDebitoEIsencaoAgtTest.php
@@ -0,0 +1,210 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Http\Client\Request;
+use Illuminate\Support\Facades\Http;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Exceptions\AgtException;
+use Modules\Faturacao\Models\AgtSubmissao;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Services\AgtDocumentoBuilder;
+use Modules\Faturacao\Services\AgtSerieService;
+use Modules\Faturacao\Services\AgtSubmissaoService;
+use Modules\Faturacao\Services\FaturaService;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Modules\Faturacao\Support\Jws;
+use Tests\TestCase;
+
+/**
+ * Como a nota de débito (sem imposto) e as linhas isentas seguem para a AGT:
+ * taxType NS / IVA+ISE com taxExemptionCode — e só com a tabela de motivos confirmada.
+ */
+class NotaDebitoEIsencaoAgtTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
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
+            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
+            'faturacao.motivos_isencao_confirmados' => true,
+            'faturacao.agt.nd_codigo_nao_sujeicao' => 'M02',
+        ]);
+
+        Http::fake([
+            '*solicitarSerie' => fn (Request $r) => Http::response([
+                'resultCode' => 1,
+                'errorList' => [''],
+                'seriesFEResult' => [
+                    'seriesCode' => $r['documentType'].'6325S2C',
+                    'authorizedQuantity' => '1000',
+                    'firstDocumentNo' => '1',
+                    'lastDocumentNo' => '1000',
+                ],
+            ], 200),
+            '*registarFactura' => Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
+        ]);
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);
+
+        foreach (['FT', 'NC', 'ND'] as $tipo) {
+            app(AgtSerieService::class)->solicitar($this->empresa, $tipo, now()->year);
+        }
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
+    /** @return array<int, array<string, mixed>> */
+    protected function linhaIsenta(string $motivo): array
+    {
+        return [['descricao' => 'Livro', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0, 'motivo_isencao' => $motivo]];
+    }
+
+    /** @return array<string, mixed> */
+    protected function construido(object $documento): array
+    {
+        return json_decode(Jws::canonico(app(AgtDocumentoBuilder::class)->construir($documento)), true);
+    }
+
+    protected function notaDeDebito(): object
+    {
+        $notas = app(NotaCreditoDebitoService::class);
+
+        return $notas->emitir($notas->criarRascunho($this->empresa->id, $this->cliente->id, 'debito', 'Repasse de despesas', [
+            ['descricao' => 'Repasse de despesas', 'quantidade' => 1, 'preco_unitario' => '250.00'],
+        ]));
+    }
+
+    public function test_nota_de_debito_segue_para_a_agt_como_nao_sujeita_com_codigo(): void
+    {
+        $doc = $this->construido($this->notaDeDebito());
+
+        $this->assertSame('ND', $doc['documentType']);
+        $this->assertSame(
+            ['taxType' => 'NS', 'taxCountryRegion' => 'AO', 'taxPercentage' => 0, 'taxContribution' => 0, 'taxExemptionCode' => 'M02'],
+            $doc['lines'][0]['taxes'][0],
+        );
+        $this->assertSame(250, $doc['lines'][0]['creditAmount']);
+        $this->assertSame(['taxPayable' => 0, 'netTotal' => 250, 'grossTotal' => 250], $doc['documentTotals']);
+    }
+
+    public function test_nota_de_debito_sem_codigo_configurado_fica_em_erro_e_nao_e_enviada(): void
+    {
+        $this->notaDeDebito();
+        config(['faturacao.agt.nd_codigo_nao_sujeicao' => null]);
+
+        $res = app(AgtSubmissaoService::class)->enviarPendentes();
+
+        $this->assertSame(1, $res['erros']);
+        $submissao = AgtSubmissao::query()->where('documento_no', 'like', 'ND %')->firstOrFail();
+        $this->assertSame('erro', $submissao->estado);
+        $this->assertStringContainsString('FATURACAO_AGT_ND_CODIGO_NS', $submissao->erros[0]['descricao']);
+        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'registarFactura'));
+    }
+
+    public function test_codigo_de_nao_sujeicao_tem_de_ser_do_tipo_ns(): void
+    {
+        $nota = $this->notaDeDebito();
+        config(['faturacao.agt.nd_codigo_nao_sujeicao' => 'M13']); // M13 é uma isenção (ISE), não uma não sujeição
+
+        $this->expectException(AgtException::class);
+        $this->expectExceptionMessage('FATURACAO_AGT_ND_CODIGO_NS');
+
+        app(AgtDocumentoBuilder::class)->construir($nota);
+    }
+
+    public function test_tabela_de_motivos_por_confirmar_bloqueia_o_envio(): void
+    {
+        $nota = $this->notaDeDebito();
+        config(['faturacao.motivos_isencao_confirmados' => false]);
+
+        $this->expectException(AgtException::class);
+        $this->expectExceptionMessage('FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS');
+
+        app(AgtDocumentoBuilder::class)->construir($nota);
+    }
+
+    public function test_linha_isenta_segue_como_iva_ise_com_taxexemptioncode(): void
+    {
+        $faturas = app(FaturaService::class);
+        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linhaIsenta('M13')));
+
+        $doc = $this->construido($fatura);
+
+        $this->assertSame(
+            ['taxType' => 'IVA', 'taxCountryRegion' => 'AO', 'taxCode' => 'ISE', 'taxPercentage' => 0, 'taxContribution' => 0, 'taxExemptionCode' => 'M13'],
+            $doc['lines'][0]['taxes'][0],
+        );
+        $this->assertSame(['taxPayable' => 0, 'netTotal' => 100, 'grossTotal' => 100], $doc['documentTotals']);
+    }
+
+    public function test_linha_nao_sujeita_segue_como_ns_sem_taxcode(): void
+    {
+        $faturas = app(FaturaService::class);
+        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linhaIsenta('M02')));
+
+        $imposto = $this->construido($fatura)['lines'][0]['taxes'][0];
+
+        $this->assertSame('NS', $imposto['taxType']);
+        $this->assertArrayNotHasKey('taxCode', $imposto);
+        $this->assertSame('M02', $imposto['taxExemptionCode']);
+    }
+
+    public function test_linha_com_iva_continua_igual(): void
+    {
+        $faturas = app(FaturaService::class);
+        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
+        ]));
+
+        $this->assertSame(
+            ['taxType' => 'IVA', 'taxCountryRegion' => 'AO', 'taxCode' => 'NOR', 'taxPercentage' => 14, 'taxContribution' => 140],
+            $this->construido($fatura)['lines'][0]['taxes'][0],
+        );
+    }
+
+    public function test_isencao_e_recusada_logo_na_criacao_enquanto_a_tabela_nao_estiver_confirmada(): void
+    {
+        config(['faturacao.motivos_isencao_confirmados' => false]);
+
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS');
+
+        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linhaIsenta('M13'));
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/NotaDebitoSemIvaTest.php b/Modules/Faturacao/tests/Feature/NotaDebitoSemIvaTest.php
new file mode 100644
index 0000000..19ca711
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/NotaDebitoSemIvaTest.php
@@ -0,0 +1,118 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\DB;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\NotaCreditoDebito;
+use Modules\Faturacao\Services\FaturaService;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Tests\TestCase;
+
+/**
+ * Nota de débito (Decreto Presidencial n.º 71/25, art. 3.º, al. m): documento
+ * em que não deve haver liquidação de imposto.
+ */
+class NotaDebitoSemIvaTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
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
+        $this->empresa = Empresa::create(['nome_comercial' => 'Loja ND', 'nif' => '5001636863']);
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente ND', 'nif' => null]);
+    }
+
+    /** @return array<int, array<string, mixed>> */
+    protected function linhas(array $extra = []): array
+    {
+        return [array_merge(['descricao' => 'Repasse de despesas', 'quantidade' => 1, 'preco_unitario' => '100.00'], $extra)];
+    }
+
+    protected function criarDebito(array $linhas): NotaCreditoDebito
+    {
+        return app(NotaCreditoDebitoService::class)->criarRascunho($this->empresa->id, $this->cliente->id, 'debito', 'Repasse', $linhas);
+    }
+
+    public function test_nota_de_debito_com_iva_e_recusada(): void
+    {
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('não pode liquidar IVA');
+
+        $this->criarDebito($this->linhas(['taxa_iva' => 14]));
+    }
+
+    public function test_nota_de_debito_sem_taxa_fica_a_zero_e_e_emitida(): void
+    {
+        $nota = $this->criarDebito($this->linhas());
+
+        $this->assertSame('100.00', $nota->valor_sem_iva);
+        $this->assertSame('0.00', $nota->valor_iva);
+        $this->assertSame('100.00', $nota->valor_total);
+        $this->assertNull($nota->linhas()->first()->motivo_isencao);
+
+        $emitida = app(NotaCreditoDebitoService::class)->emitir($nota);
+
+        $this->assertSame('emitida', $emitida->estado);
+        $this->assertStringStartsWith('ND ', $emitida->numero_documento);
+        $this->assertSame('0.00', $emitida->valor_iva);
+    }
+
+    public function test_taxa_vazia_ou_zero_na_nota_de_debito_vale_zero(): void
+    {
+        foreach (['', '0', 0, '0.00'] as $taxa) {
+            $nota = $this->criarDebito($this->linhas(['taxa_iva' => $taxa]));
+
+            $this->assertSame('0.00', $nota->valor_iva, 'taxa '.var_export($taxa, true));
+        }
+    }
+
+    public function test_motivo_de_isencao_enviado_numa_nota_de_debito_e_ignorado(): void
+    {
+        $nota = $this->criarDebito($this->linhas(['motivo_isencao' => 'M10']));
+
+        $this->assertNull($nota->linhas()->first()->motivo_isencao);
+    }
+
+    public function test_rascunho_de_debito_com_iva_criado_por_outro_caminho_nao_e_emitido(): void
+    {
+        $nota = $this->criarDebito($this->linhas());
+
+        // Alguém altera o rascunho diretamente na base de dados.
+        DB::table('notas_credito_debito_linhas')->where('nota_credito_debito_id', $nota->id)
+            ->update(['taxa_iva' => 14, 'valor_iva' => '14.00', 'valor_total' => '114.00']);
+
+        $this->expectException(\DomainException::class);
+        $this->expectExceptionMessage('não pode liquidar IVA');
+
+        app(NotaCreditoDebitoService::class)->emitir($nota);
+    }
+
+    public function test_nota_de_credito_continua_a_aceitar_iva(): void
+    {
+        $linhas = $this->linhas(['taxa_iva' => 14]);
+        $fatura = app(FaturaService::class);
+        $emitida = $fatura->emitir($fatura->criarRascunho($this->empresa, $this->cliente, $linhas));
+
+        $nc = app(NotaCreditoDebitoService::class);
+        $credito = $nc->emitir($nc->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', 'Devolução', $linhas, $emitida->id));
+
+        $this->assertSame('14.00', $credito->valor_iva);
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/SafTEstruturaEVersoesTest.php b/Modules/Faturacao/tests/Feature/SafTEstruturaEVersoesTest.php
new file mode 100644
index 0000000..3ebefe4
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/SafTEstruturaEVersoesTest.php
@@ -0,0 +1,271 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use DOMDocument;
+use DOMXPath;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Storage;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Models\User;
+use Modules\Core\Support\Servico;
+use Modules\Faturacao\Jobs\GerarSafTJob;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Services\FaturaService;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Modules\Faturacao\Services\SafTExportService;
+use Spatie\Permission\Models\Role;
+use Spatie\Permission\PermissionRegistrar;
+use Tests\TestCase;
+
+/**
+ * SAF-T: estrutura provisória com TaxTable e DocumentTotals, validação contra o XSD
+ * oficial (quando configurado), ficheiros versionados com metadados e descarga
+ * condicionada em produção.
+ */
+class SafTEstruturaEVersoesTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
+
+    protected string $tmp;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->withoutVite();
+        Storage::fake('local');
+
+        $this->tmp = sys_get_temp_dir().'/saft-'.uniqid();
+        mkdir($this->tmp);
+        $this->beforeApplicationDestroyed(function () {
+            foreach (glob($this->tmp.'/*') ?: [] as $f) {
+                @unlink($f);
+            }
+            @rmdir($this->tmp);
+        });
+
+        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
+        openssl_pkey_export($recurso, $pem);
+        file_put_contents($this->tmp.'/chave.pem', $pem);
+
+        config([
+            'faturacao.chave_privada_path' => $this->tmp.'/chave.pem',
+            'faturacao.agt.ativo' => false,
+            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
+            'faturacao.agt.nd_codigo_nao_sujeicao' => 'M02',
+            'faturacao.saft.xsd_path' => null,
+        ]);
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Loja SAFT', 'nif' => '5001636863']);
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
+    }
+
+    protected function emitirDocumentos(): void
+    {
+        $faturas = app(FaturaService::class);
+        $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
+        ]));
+        $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Livro', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0, 'motivo_isencao' => 'M13'],
+        ]));
+        $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
+            ['descricao' => 'Operação não sujeita', 'quantidade' => 1, 'preco_unitario' => '50.00', 'taxa_iva' => 0, 'motivo_isencao' => 'M02'],
+        ]));
+
+        $notas = app(NotaCreditoDebitoService::class);
+        $notas->emitir($notas->criarRascunho($this->empresa->id, $this->cliente->id, 'debito', 'Repasse', [
+            ['descricao' => 'Repasse de despesas', 'quantidade' => 1, 'preco_unitario' => '25.00'],
+        ]));
+    }
+
+    protected function xml(): string
+    {
+        return app(SafTExportService::class)->gerar($this->empresa, now()->startOfYear(), now()->endOfYear());
+    }
+
+    protected function xpath(string $xml): DOMXPath
+    {
+        $dom = new DOMDocument;
+        $dom->loadXML($xml);
+
+        return new DOMXPath($dom);
+    }
+
+    public function test_tem_taxtable_com_os_impostos_usados_e_documenttotals_em_cada_documento(): void
+    {
+        $this->emitirDocumentos();
+        $xp = $this->xpath($this->xml());
+
+        $entradas = collect($xp->query('//MasterFiles/TaxTable/TaxTableEntry'))
+            ->map(fn ($e) => $xp->evaluate('string(TaxType)', $e).'/'.$xp->evaluate('string(TaxCode)', $e).'/'.$xp->evaluate('string(TaxPercentage)', $e))
+            ->sort()->values()->all();
+
+        $this->assertSame(['IVA/ISE/0.00', 'IVA/NOR/14.00', 'NS/NS/0.00'], $entradas);
+
+        $this->assertSame(4, (int) $xp->evaluate('count(//Invoice/DocumentTotals)'));
+        $this->assertSame(0, (int) $xp->evaluate('count(//Invoice/GrossTotal)'), 'Os totais já não ficam soltos na fatura.');
+        $this->assertSame('1140.00', $xp->evaluate('string((//Invoice/DocumentTotals/GrossTotal)[1])'));
+        $this->assertSame('140.00', $xp->evaluate('string((//Invoice/DocumentTotals/TaxPayable)[1])'));
+    }
+
+    public function test_linhas_isentas_nao_sujeitas_e_notas_de_debito_levam_o_codigo_de_isencao(): void
+    {
+        $this->emitirDocumentos();
+        $xp = $this->xpath($this->xml());
+
+        $this->assertSame('ISE', $xp->evaluate('string(//Invoice[Line/Description="Livro"]/Line/Tax/TaxCode)'));
+        $this->assertSame('M13', $xp->evaluate('string(//Invoice[Line/Description="Livro"]/Line/TaxExemptionCode)'));
+
+        $this->assertSame('NS', $xp->evaluate('string(//Invoice[Line/Description="Operação não sujeita"]/Line/Tax/TaxType)'));
+        $this->assertSame('M02', $xp->evaluate('string(//Invoice[Line/Description="Operação não sujeita"]/Line/TaxExemptionCode)'));
+
+        $this->assertSame('ND', $xp->evaluate('string(//Invoice[Line/Description="Repasse de despesas"]/InvoiceType)'));
+        $this->assertSame('NS', $xp->evaluate('string(//Invoice[Line/Description="Repasse de despesas"]/Line/Tax/TaxType)'));
+        $this->assertSame('0.00', $xp->evaluate('string(//Invoice[Line/Description="Repasse de despesas"]/DocumentTotals/TaxPayable)'));
+
+        $this->assertSame('', $xp->evaluate('string(//Invoice[Line/Description="Serviço"]/Line/TaxExemptionCode)'));
+    }
+
+    public function test_o_ficheiro_leva_a_marca_de_estrutura_provisoria(): void
+    {
+        $this->assertStringContainsString('ESTRUTURA PROVISÓRIA', $this->xml());
+    }
+
+    public function test_sem_xsd_nunca_se_finge_validacao(): void
+    {
+        $this->assertSame(['estado' => 'nao_validado', 'erros' => []], app(SafTExportService::class)->validar($this->xml()));
+
+        config(['faturacao.saft.xsd_path' => $this->tmp.'/nao-existe.xsd']);
+
+        $this->assertSame('nao_validado', app(SafTExportService::class)->validar($this->xml())['estado']);
+    }
+
+    public function test_validacao_contra_o_xsd_configurado(): void
+    {
+        $permissivo = $this->tmp.'/permissivo.xsd';
+        file_put_contents($permissivo, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="AuditFile" type="xs:anyType"/></xs:schema>');
+
+        $exigente = $this->tmp.'/exigente.xsd';
+        file_put_contents($exigente, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="AuditFile"><xs:complexType><xs:sequence><xs:element name="Cabecalho" type="xs:string"/></xs:sequence></xs:complexType></xs:element></xs:schema>');
+
+        $servico = app(SafTExportService::class);
+        $xml = $this->xml();
+
+        config(['faturacao.saft.xsd_path' => $permissivo]);
+        $this->assertSame(['estado' => 'valido_xsd', 'erros' => []], $servico->validar($xml));
+
+        config(['faturacao.saft.xsd_path' => $exigente]);
+        $resultado = $servico->validar($xml);
+        $this->assertSame('invalido_xsd', $resultado['estado']);
+        $this->assertNotEmpty($resultado['erros']);
+
+        $this->assertSame('invalido_xsd', $servico->validar('isto não é xml')['estado']);
+    }
+
+    public function test_o_job_guarda_cada_geracao_como_versao_nova_com_metadados(): void
+    {
+        $this->emitirDocumentos();
+        $disco = Storage::disk('local');
+
+        GerarSafTJob::dispatchSync($this->empresa, now()->startOfYear()->toDateString(), now()->toDateString());
+        GerarSafTJob::dispatchSync($this->empresa, now()->startOfYear()->toDateString(), now()->toDateString());
+
+        $xmls = collect($disco->files("faturacao/saft/{$this->empresa->id}"))->filter(fn ($c) => str_ends_with($c, '.xml'))->values();
+        $metas = collect($disco->files("faturacao/saft/{$this->empresa->id}"))->filter(fn ($c) => str_ends_with($c, '.json'));
+
+        $this->assertCount(2, $xmls, 'A segunda geração não substitui a primeira.');
+        $this->assertCount(2, $metas);
+        $this->assertMatchesRegularExpression('/SAFT_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}_\d{14}(-\d+)?\.xml$/', $xmls[0]);
+
+        $meta = json_decode($disco->get(preg_replace('/\.xml$/', '.json', $xmls[0])), true);
+        $this->assertSame(hash('sha256', $disco->get($xmls[0])), $meta['sha256']);
+        $this->assertSame('nao_validado', $meta['estado']);
+        $this->assertSame('provisoria', $meta['estrutura']);
+        $this->assertSame(strlen($disco->get($xmls[0])), $meta['tamanho_bytes']);
+    }
+
+    /** @return array{0: User, 1: string} administrador autenticável e nome do ficheiro SAF-T já gerado */
+    protected function administradorComFicheiro(): array
+    {
+        $this->empresa->aderirServicos([Servico::Faturacao]);
+
+        $admin = User::create([
+            'empresa_id' => $this->empresa->id,
+            'name' => 'Administrador',
+            'email' => 'admin@saft.ao',
+            'password' => 'Senha-Forte-123!',
+        ]);
+        app(PermissionRegistrar::class)->setPermissionsTeamId($this->empresa->id);
+        $admin->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $this->empresa->id]));
+
+        GerarSafTJob::dispatchSync($this->empresa, now()->startOfYear()->toDateString(), now()->toDateString());
+
+        $nome = basename(collect(Storage::disk('local')->files("faturacao/saft/{$this->empresa->id}"))->first(fn ($c) => str_ends_with($c, '.xml')));
+
+        return [$admin, $nome];
+    }
+
+    public function test_listagem_mostra_o_estado_da_validacao(): void
+    {
+        [$admin] = $this->administradorComFicheiro();
+
+        $this->actingAs($admin)->get(route('faturacao.saft.criar'))
+            ->assertOk()
+            ->assertSee('Não validado')
+            ->assertSee('SHA-256');
+    }
+
+    public function test_fora_de_producao_descarrega_mesmo_sem_validacao(): void
+    {
+        [$admin, $nome] = $this->administradorComFicheiro();
+
+        $this->actingAs($admin)->get(route('faturacao.saft.descarregar', $nome))->assertOk();
+    }
+
+    public function test_em_producao_so_se_descarrega_o_que_o_xsd_validou(): void
+    {
+        [$admin, $nome] = $this->administradorComFicheiro();
+
+        $this->app->detectEnvironment(fn () => 'production');
+        config(['faturacao.saft_numero_certificado' => 'C_134']);
+
+        $this->actingAs($admin)->get(route('faturacao.saft.descarregar', $nome))->assertForbidden();
+
+        // Marcado como validado nos metadados: passa a ser descarregável.
+        $meta = "faturacao/saft/{$this->empresa->id}/".preg_replace('/\.xml$/', '.json', $nome);
+        $dados = json_decode(Storage::disk('local')->get($meta), true);
+        Storage::disk('local')->put($meta, json_encode([...$dados, 'estado' => 'valido_xsd']));
+
+        $this->actingAs($admin)->get(route('faturacao.saft.descarregar', $nome))->assertOk();
+    }
+
+    public function test_nome_de_ficheiro_invalido_continua_a_dar_404(): void
+    {
+        [$admin] = $this->administradorComFicheiro();
+
+        $this->actingAs($admin)->get(route('faturacao.saft.descarregar', '..passwd.xml'))->assertNotFound();
+        $this->actingAs($admin)->get(route('faturacao.saft.descarregar', 'SAFT_2026-01-01_2026-12-31_x.xml'))->assertNotFound();
+    }
+
+    public function test_formulario_de_fatura_so_oferece_motivo_de_isencao_quando_a_taxa_zero_e_permitida(): void
+    {
+        [$admin] = $this->administradorComFicheiro();
+
+        $this->actingAs($admin)->get(route('faturacao.faturas.criar'))
+            ->assertOk()
+            ->assertSee('Motivo de isenção...')
+            ->assertSee('M13');
+
+        config(['faturacao.taxas_iva_permitidas' => [14.0]]);
+
+        $this->actingAs($admin)->get(route('faturacao.faturas.criar'))
+            ->assertOk()
+            ->assertDontSee('Motivo de isenção...');
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/SerieEstabelecimentoTest.php b/Modules/Faturacao/tests/Feature/SerieEstabelecimentoTest.php
new file mode 100644
index 0000000..bc04173
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/SerieEstabelecimentoTest.php
@@ -0,0 +1,67 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Exceptions\AgtException;
+use Modules\Faturacao\Models\Serie;
+use Modules\Faturacao\Services\NumeracaoService;
+use Tests\TestCase;
+
+/** Séries autorizadas pela AGT são por estabelecimento: nunca se usa a de outro. */
+class SerieEstabelecimentoTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        config(['faturacao.agt.ativo' => true, 'faturacao.agt.estabelecimento_padrao' => 'SEDE']);
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Dois Estabelecimentos', 'nif' => '5001636863']);
+    }
+
+    protected function serie(string $codigo, string $estabelecimento): Serie
+    {
+        return Serie::create([
+            'empresa_id' => $this->empresa->id,
+            'tipo_documento' => 'FT',
+            'ano' => now()->year,
+            'prefixo' => $codigo, // tal como AgtSerieService::guardar()
+            'ultimo_numero' => 0,
+            'agt_codigo' => $codigo,
+            'estabelecimento' => $estabelecimento,
+            'contingencia' => 'N',
+            'quantidade_autorizada' => 1000,
+            'ultimo_numero_autorizado' => 1000,
+        ]);
+    }
+
+    public function test_cada_estabelecimento_usa_a_sua_propria_serie(): void
+    {
+        $this->serie('FT1SEDE', 'SEDE');
+        $this->serie('FT1LOJA2', 'LOJA2');
+        $numeracao = app(NumeracaoService::class);
+
+        $padrao = $numeracao->proximoNumero($this->empresa->id, 'FT');
+        $loja2 = $numeracao->proximoNumero($this->empresa->id, 'FT', 'A', 'LOJA2');
+        $loja2Outra = $numeracao->proximoNumero($this->empresa->id, 'FT', 'A', 'LOJA2');
+
+        $this->assertSame('FT FT1SEDE/1', $padrao['numero_documento']);
+        $this->assertSame('FT FT1LOJA2/1', $loja2['numero_documento']);
+        $this->assertSame('FT FT1LOJA2/2', $loja2Outra['numero_documento']);
+    }
+
+    public function test_estabelecimento_sem_serie_autorizada_nao_herda_a_de_outro(): void
+    {
+        $this->serie('FT1SEDE', 'SEDE');
+
+        $this->expectException(AgtException::class);
+
+        app(NumeracaoService::class)->proximoNumero($this->empresa->id, 'FT', 'A', 'LOJA3');
+    }
+}
diff --git a/Modules/Faturacao/tests/Feature/VerificarCadeiaMultiEmpresaTest.php b/Modules/Faturacao/tests/Feature/VerificarCadeiaMultiEmpresaTest.php
new file mode 100644
index 0000000..9c18f3b
--- /dev/null
+++ b/Modules/Faturacao/tests/Feature/VerificarCadeiaMultiEmpresaTest.php
@@ -0,0 +1,196 @@
+<?php
+
+namespace Modules\Faturacao\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Http\Client\Request;
+use Illuminate\Support\Facades\Artisan;
+use Illuminate\Support\Facades\Http;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Models\AgtSubmissao;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\Serie;
+use Modules\Faturacao\Services\AgtSerieService;
+use Modules\Faturacao\Services\FaturaService;
+use Tests\TestCase;
+
+/** Auditoria da cadeia com várias empresas (uma chave cada) e com correspondência AGT. */
+class VerificarCadeiaMultiEmpresaTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected string $dir;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->dir = sys_get_temp_dir().'/cadeia-'.uniqid();
+        mkdir($this->dir);
+        $this->beforeApplicationDestroyed(function () {
+            foreach (glob($this->dir.'/*') ?: [] as $f) {
+                @unlink($f);
+            }
+            @rmdir($this->dir);
+        });
+
+        $this->gerarChave($this->dir.'/generica.pem');
+        config(['faturacao.chaves_dir' => $this->dir, 'faturacao.chave_privada_path' => $this->dir.'/generica.pem', 'faturacao.agt.ativo' => false]);
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
+    /** @return array{0: Empresa, 1: Cliente} */
+    protected function empresaComChave(string $nif): array
+    {
+        $this->gerarChave("{$this->dir}/{$nif}.pem");
+        $empresa = Empresa::create(['nome_comercial' => "Empresa {$nif}", 'nif' => $nif]);
+        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);
+
+        return [$empresa, $cliente];
+    }
+
+    protected function emitir(Empresa $empresa, Cliente $cliente): Fatura
+    {
+        $faturas = app(FaturaService::class);
+
+        return $faturas->emitir($faturas->criarRascunho($empresa, $cliente, [
+            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14],
+        ]));
+    }
+
+    /** @return array{0: int, 1: string} */
+    protected function auditar(array $opcoes = []): array
+    {
+        $codigo = Artisan::call('faturacao:verificar-cadeia', $opcoes);
+
+        return [$codigo, Artisan::output()];
+    }
+
+    public function test_cada_empresa_e_verificada_com_a_sua_propria_chave(): void
+    {
+        [$a, $clienteA] = $this->empresaComChave('5001636863');
+        [$b, $clienteB] = $this->empresaComChave('5001636864');
+        $this->emitir($a, $clienteA);
+        $this->emitir($a, $clienteA);
+        $this->emitir($b, $clienteB);
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(0, $codigo, $saida);
+        $this->assertStringContainsString('3 documento(s) verificados', $saida);
+    }
+
+    public function test_assinatura_com_outra_chave_e_detetada(): void
+    {
+        [$a, $clienteA] = $this->empresaComChave('5001636863');
+        $this->emitir($a, $clienteA);
+
+        // A chave da empresa é trocada depois da emissão: o documento já não confere.
+        $this->gerarChave($this->dir.'/5001636863.pem');
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(1, $codigo);
+        $this->assertStringContainsString('assinatura RSA inválida', $saida);
+    }
+
+    public function test_sem_chave_publica_a_empresa_fica_assinalada(): void
+    {
+        [$a, $clienteA] = $this->empresaComChave('5001636863');
+        $this->emitir($a, $clienteA);
+        unlink($this->dir.'/5001636863.pem');
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(1, $codigo);
+        $this->assertStringContainsString('sem chave pública', $saida);
+    }
+
+    public function test_chave_publica_indicada_continua_a_valer_para_todas_as_empresas(): void
+    {
+        [$a, $clienteA] = $this->empresaComChave('5001636863');
+        $this->emitir($a, $clienteA);
+
+        $publica = $this->dir.'/publica.pem';
+        $privada = openssl_pkey_get_private((string) file_get_contents($this->dir.'/5001636863.pem'));
+        file_put_contents($publica, openssl_pkey_get_details($privada)['key']);
+
+        [$codigo] = $this->auditar(['--chave-publica' => $publica]);
+        $this->assertSame(0, $codigo);
+
+        [$codigo, $saida] = $this->auditar(['--chave-publica' => $this->dir.'/nao-existe.pem']);
+        $this->assertSame(1, $codigo);
+        $this->assertStringContainsString('Chave pública não encontrada', $saida);
+    }
+
+    protected function prepararAgt(Empresa $empresa): void
+    {
+        $software = $this->gerarChave($this->dir.'/software.pem');
+
+        config([
+            'faturacao.agt.ativo' => true,
+            'faturacao.agt.ambiente' => 'homologacao',
+            'faturacao.agt.utilizador' => 'produtor',
+            'faturacao.agt.palavra_passe' => 's3nh@',
+            'faturacao.agt.software.chave_privada_path' => $software,
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
+        ]);
+
+        app(AgtSerieService::class)->solicitar($empresa, 'FT', now()->year);
+    }
+
+    public function test_com_agt_cada_documento_tem_de_ter_submissao_e_so_valido_e_confirmacao(): void
+    {
+        [$a, $clienteA] = $this->empresaComChave('5001636863');
+        $this->prepararAgt($a);
+        $fatura = $this->emitir($a, $clienteA);
+
+        // Pendente: a cadeia está íntegra, mas a AGT ainda não confirmou — aviso, não erro.
+        [$codigo, $saida] = $this->auditar(['--agt' => true]);
+        $this->assertSame(0, $codigo, $saida);
+        $this->assertStringContainsString('Pendente de envio', $saida);
+
+        AgtSubmissao::query()->where('documento_id', $fatura->id)->update(['estado' => AgtSubmissao::VALIDO]);
+        [$codigo, $saida] = $this->auditar(['--agt' => true]);
+        $this->assertSame(0, $codigo, $saida);
+        $this->assertStringNotContainsString('Pendente', $saida);
+
+        AgtSubmissao::query()->where('documento_id', $fatura->id)->delete();
+        [$codigo, $saida] = $this->auditar(['--agt' => true]);
+        $this->assertSame(1, $codigo);
+        $this->assertStringContainsString('sem registo de submissão', $saida);
+    }
+
+    public function test_numero_fora_do_intervalo_autorizado_pela_agt_e_detetado(): void
+    {
+        [$a, $clienteA] = $this->empresaComChave('5001636863');
+        $this->prepararAgt($a);
+        $this->emitir($a, $clienteA);
+        $this->emitir($a, $clienteA);
+
+        Serie::withoutGlobalScopes()->where('empresa_id', $a->id)->update(['ultimo_numero_autorizado' => 1]);
+
+        [$codigo, $saida] = $this->auditar();
+
+        $this->assertSame(1, $codigo);
+        $this->assertStringContainsString('fora do intervalo autorizado', $saida);
+    }
+}
diff --git a/bootstrap/app.php b/bootstrap/app.php
index eb01eb1..aac346e 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -8,9 +8,11 @@
 use Modules\Core\Console\ProvisionarServicosCommand;
 use Modules\Core\Http\Middleware\IdentificarTenant;
 use Modules\Core\Support\ProxiesConfiaveis;
+use Modules\Faturacao\Console\CorrigirDocumentoAgtCommand;
 use Modules\Faturacao\Console\SincronizarAgtCommand;
 use Modules\Faturacao\Console\SolicitarSerieAgtCommand;
 use Modules\Faturacao\Console\VerificarCadeiaFiscalCommand;
+use Modules\Faturacao\Console\VerificarChavesCommand;
 use Spatie\Permission\Middleware\PermissionMiddleware;
 use Spatie\Permission\Middleware\RoleMiddleware;
 use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
@@ -26,6 +28,8 @@
         VerificarCadeiaFiscalCommand::class,
         SolicitarSerieAgtCommand::class,
         SincronizarAgtCommand::class,
+        CorrigirDocumentoAgtCommand::class,
+        VerificarChavesCommand::class,
     ])
     ->withMiddleware(function (Middleware $middleware): void {
         // Proxies de confiança: NUNCA '*' por omissão. Em produção define
