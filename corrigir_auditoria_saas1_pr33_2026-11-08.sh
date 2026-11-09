#!/usr/bin/env bash
# 
 — hardening controlado da PR #33
# Data: 2026-11-08
#
# Este script NÃO altera main, NÃO faz merge e NÃO faz push.
# Aplica apenas correções mecânicas verificáveis e prepara evidências.
# Bloqueios AGT sem XSD/especificação oficial não são "resolvidos" por suposição.

set -Eeuo pipefail
IFS=$'\n\t'

REPO_ESPERADO="luismanueljoao6-cell/Saas1"
BRANCH_ESPERADA="feat/faturacao-fiscal-core"
PR_NUMERO="33"
ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || {
  echo "ERRO: executa este script dentro do repositório Git Saas1." >&2; exit 1;
}
cd "$ROOT"

log()  { printf '\n[SAAS1] %s\n' "$*"; }
fail() { printf '\n[ERRO] %s\n' "$*" >&2; exit 1; }

[[ "$(basename "$ROOT")" == "Saas1" ]] || fail "Pasta inesperada: $ROOT"
git remote get-url origin | grep -qi 'luismanueljoao6-cell/Saas1' \
  || fail "O remote origin não corresponde a $REPO_ESPERADO."

BRANCH_ATUAL="$(git branch --show-current)"
[[ "$BRANCH_ATUAL" == "$BRANCH_ESPERADA" ]] \
  || fail "Branch atual '$BRANCH_ATUAL'. Muda manualmente para '$BRANCH_ESPERADA' e volta a executar. A main não será tocada."

[[ "$BRANCH_ATUAL" != "main" ]] || fail "Proteção: nunca executar na main."
git diff --quiet && git diff --cached --quiet \
  || fail "Existem alterações locais não guardadas. Faz commit/stash/backup antes; o script não as vai sobrescrever."

log "Validar branch e estado remoto"
git fetch origin main "$BRANCH_ESPERADA" --quiet
LOCAL="$(git rev-parse HEAD)"
REMOTO="$(git rev-parse "origin/$BRANCH_ESPERADA")"
[[ "$LOCAL" == "$REMOTO" ]] || fail "A branch local não está sincronizada com origin/$BRANCH_ESPERADA. Faz pull --ff-only e reexecuta."
MAIN_SHA="$(git rev-parse origin/main)"
log "HEAD da branch: $LOCAL"
log "HEAD da main:   $MAIN_SHA (não será alterada)"

BACKUP_DIR=".saas1-audit-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
FILES=(
  "Modules/Atelier/app/Services/CampanhaReconciliador.php"
  "Modules/Faturacao/app/Support/Dinheiro.php"
  "Modules/Atelier/app/Services/PedidoFaturacaoService.php"
  "Modules/Faturacao/app/Services/ReciboService.php"
)
for f in "${FILES[@]}"; do
  [[ -f "$f" ]] && cp --parents "$f" "$BACKUP_DIR/"
done
log "Backup dos ficheiros visados em $BACKUP_DIR"

python3 <<'PY'
from pathlib import Path

# 1) Lock do reconciliador: o job de campanha declara timeout=900 s e
#    adquire lock por timeout+60 s. O reconciliador usava 120 s na mesma chave.
p = Path("Modules/Atelier/app/Services/CampanhaReconciliador.php")
s = p.read_text()
old = "$lock = Cache::lock('atelier:campanha:'.$campanha->id, 120);"
new = "$lock = Cache::lock('atelier:campanha:'.$campanha->id, 960);"
if old not in s:
    if new not in s:
        raise SystemExit("ERRO: padrão exato do lock do reconciliador não encontrado; nenhuma substituição parcial será aceite.")
else:
    p.write_text(s.replace(old, new, 1))

# 2) Fronteiras monetárias: deixar de aceitar float nas assinaturas de
#    Dinheiro, PedidoFaturacaoService e ReciboService. O gateway já usa string|int.
#    O parser rejeita float explicitamente para impedir perda silenciosa de precisão.
targets = [
    Path("Modules/Faturacao/app/Support/Dinheiro.php"),
    Path("Modules/Atelier/app/Services/PedidoFaturacaoService.php"),
    Path("Modules/Faturacao/app/Services/ReciboService.php"),
]
for p in targets:
    s = p.read_text()
    s2 = s.replace("string|int|float|null", "string|int|null")
    s2 = s2.replace("string|int|float", "string|int")
    if p.name == "Dinheiro.php":
        needle = "        if ($valor === null || $valor === '') {"
        guard = """        if (is_float($valor)) {
            throw new \\InvalidArgumentException('Valores monetários devem ser enviados como string decimal ou inteiro; float não é permitido.');
        }

"""
        if needle not in s2:
            raise SystemExit("ERRO: ponto de inserção do parser Dinheiro não encontrado.")
        if guard.strip() not in s2:
            s2 = s2.replace(needle, guard + needle, 1)
    if s2 != s:
        p.write_text(s2)

PY

log "Correções mecânicas aplicadas. Verificar alterações."
git diff --check || fail "git diff --check falhou. Restaurar os ficheiros a partir de $BACKUP_DIR e investigar."
git diff --stat

log "Auditoria estática de floats nas fronteiras monetárias"
if rg -n --glob '*.php' 'string\|int\|float|float\|int|string\|float|is_float\(' \
  Modules/Faturacao Modules/Atelier/app/Services/PedidoFaturacaoService.php; then
  echo "AVISO: ainda existem ocorrências de float/compatibilidade. Rever antes de aprovar."
else
  echo "Sem assinaturas float detetadas nos caminhos pesquisados."
fi

log "Auditoria de lembretes do Estúdio"
rg -n 'lembrete_24h_enviado_em|lembrete_2h_enviado_em|lembreteSessao|lockForUpdate|whereNull' \
  Modules/EstudioMusica/app/Jobs/EnviarLembretesSessaoJob.php \
  Modules/EstudioMusica/app/Services/NotificacaoEstudioService.php || true

log "Auditoria SAF-T / AGT"
rg -n -i 'provis.ria|XSD|schema|SAF-T|FA|paymentReceipt|InvoiceType' \
  Modules/Faturacao/app/Services/SafTExportService.php \
  Modules/Faturacao/app/Services/ReciboService.php \
  Modules/Faturacao/docs || true

log "Validação de sintaxe PHP"
for f in \
  Modules/Atelier/app/Services/CampanhaReconciliador.php \
  Modules/Faturacao/app/Support/Dinheiro.php \
  Modules/Atelier/app/Services/PedidoFaturacaoService.php \
  Modules/Faturacao/app/Services/ReciboService.php
do
  php -l "$f" || fail "Erro de sintaxe em $f"
done

if [[ -x vendor/bin/pint ]]; then
  vendor/bin/pint --test \
    Modules/Atelier/app/Services/CampanhaReconciliador.php \
    Modules/Faturacao/app/Support/Dinheiro.php \
    Modules/Atelier/app/Services/PedidoFaturacaoService.php \
    Modules/Faturacao/app/Services/ReciboService.php \
    || fail "Pint falhou. Não fazer push."
else
  echo "AVISO: vendor/bin/pint não encontrado; instala dependências para validar estilo."
fi

if [[ -f vendor/autoload.php ]]; then
  log "Testes focados"
  php artisan test \
    Modules/Atelier/tests/Feature/ReconciliacaoCampanhasTest.php \
    Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaTest.php \
    Modules/Core/tests/Feature/NotificationProcessorTest.php \
    Modules/Faturacao/tests/Feature/GatewayFronteiraTest.php \
    Modules/Faturacao/tests/Feature/FaturacaoGatewayTest.php \
    || fail "Testes focados falharam. Não fazer push."
  log "Suite completa (pode demorar)"
  php artisan test --exclude-group=auditoria \
    || fail "Suite completa falhou. Não fazer push."
else
  echo "AVISO: vendor/autoload.php não existe; testes não executados."
fi

cat <<'EOF'

================ LIMITES QUE CONTINUAM BLOQUEADOS ================

Este script NÃO declara resolvidos os seguintes pontos, porque exigem
implementação e evidência que não podem ser inventadas por substituição textual:

1. Adiantamentos AGT (FA), liquidação/abatimento, cancelamento e trilho fiscal.
   Manter o fluxo bloqueado quando AGT estiver ativa até confirmar a versão
   oficial da especificação, payload, regras de numeração/assinatura e homologação.

2. SAF-T (AO): não ativar exportação fiscal como conforme enquanto o XML não
   validar contra o XSD oficial aplicável e os totais/documentos forem testados.

3. Outbox: timeout após aceitação remota é resultado DESCONHECIDO, não uma
   rejeição. Exige estado explícito, reconciliação por provider_message_id ou
   chave de idempotência do fornecedor; sem suporte remoto não prometer exactly-once.

4. Lembretes do Estúdio: ainda exige claim atómico persistente (por sessão,
   canal e janela), índice único/migration compatível com os dados existentes,
   e testes de concorrência no MySQL real.

5. WhatsApp/SMS: requer credenciais de homologação, templates aprovados,
   callbacks/webhooks autenticados e testes reais de aceitação/entrega/leitura.

6. AGT: confirmar com a documentação/especificação oficial vigente e processo
   formal de homologação. O portal público por si só não fornece neste contexto
   o XSD/contrato técnico completo que permita certificar conformidade.

NÃO FAZER MERGE. NÃO PROMOVER A PR PARA READY. NÃO ATIVAR ESTES FLUXOS EM PRODUÇÃO.

O script não faz commit nem push. Rever o diff, corrigir eventuais chamadas
que ainda passem float e executar os cenários de concorrência em MySQL.
Backup dos ficheiros originais: diretório indicado no início.
===================================================================

EOF

log "Concluído sem commit/push. main preservada: $MAIN_SHA"
