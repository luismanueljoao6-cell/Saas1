#!/usr/bin/env bash
# Verifica as migrations da branch contra um MySQL REAL (não SQLite):
#   1. instalação limpa            4. atualização a partir da main
#   2. triggers e FKs esperados    5. (opcional) atualização com dump de dados reais
#   3. rollback das migrations novas e nova aplicação
#
# Usa a base configurada no .env — USA UMA BASE DESCARTÁVEL: faz migrate:fresh!
# Variáveis: BASE_REF (default origin/main), DUMP_SQL (dump de uma instalação na main).
set -euo pipefail

BASE_REF="${BASE_REF:-origin/main}"
RAIZ="$(git rev-parse --show-toplevel)"
cd "$RAIZ"

[ "$(php artisan tinker --execute='echo DB::getDriverName();' 2>/dev/null | tail -1)" = "mysql" ] \
  || { echo "O .env tem de apontar para MySQL (esta verificação não faz sentido em SQLite)."; exit 1; }

consulta() { php artisan tinker --execute="echo json_encode(DB::select(\"$1\"));" 2>/dev/null | tail -1; }

verificar_triggers_e_fks() {
  local esperados=(adiantamento_abatimentos_sem_update adiantamento_abatimentos_sem_delete adiantamento_abatimentos_mesma_empresa \
    faturas_impedir_alteracao faturas_impedir_exclusao recibos_impedir_alteracao series_impedir_retrocesso)
  local triggers; triggers="$(consulta 'SHOW TRIGGERS')"
  for t in "${esperados[@]}"; do
    [[ "$triggers" == *"$t"* ]] || { echo "FALTA o trigger $t"; exit 1; }
  done
  local fks; fks="$(consulta "SELECT TABLE_NAME t, DELETE_RULE r FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'empresas' AND TABLE_NAME IN ('faturas','series','notas_credito_debito','recibos','agt_submissoes','adiantamento_abatimentos') AND DELETE_RULE <> 'RESTRICT'")"
  [ "$fks" = "[]" ] || { echo "FK da empresa ainda em CASCADE/SET NULL: $fks"; exit 1; }
  echo "   triggers e FKs OK"
}

echo "1/5 Instalação limpa"
php artisan migrate:fresh --force
verificar_triggers_e_fks

echo "2/5 Estado final sem migrations pendentes"
php artisan migrate:status | grep -q "Pending" && { echo "Há migrations pendentes."; exit 1; } || echo "   OK"

echo "3/5 Rollback das migrations novas e nova aplicação"
git fetch -q origin "${BASE_REF#origin/}" || true
NOVAS=$(git diff --name-only --diff-filter=A "$BASE_REF"...HEAD -- '*/database/migrations/*.php' | wc -l)
echo "   $NOVAS migrations novas face a $BASE_REF"
[ "$NOVAS" -gt 0 ] && php artisan migrate:rollback --step="$NOVAS" --force
php artisan migrate --force
verificar_triggers_e_fks

echo "4/5 Atualização a partir da $BASE_REF (schema da main -> schema da branch)"
TMP="$(mktemp -d)"; trap 'git worktree remove --force "$TMP" 2>/dev/null || true' EXIT
git worktree add -q --detach "$TMP" "$BASE_REF"
ln -s "$RAIZ/vendor" "$TMP/vendor"; cp "$RAIZ/.env" "$TMP/.env"
( cd "$TMP" && php artisan migrate:fresh --force )
php artisan migrate --force
verificar_triggers_e_fks

if [ -n "${DUMP_SQL:-}" ]; then
  echo "5/5 Atualização com dados reais de teste ($DUMP_SQL)"
  php artisan db:wipe --force
  DB_NAME="$(php artisan tinker --execute='echo DB::getDatabaseName();' 2>/dev/null | tail -1)"
  mysql -h "${DB_HOST:-127.0.0.1}" -u "${DB_USERNAME:-root}" -p"${DB_PASSWORD:-}" "$DB_NAME" < "$DUMP_SQL"
  ANTES="$(consulta 'SELECT (SELECT COUNT(*) FROM faturas) f, (SELECT COUNT(*) FROM recibos) r, (SELECT COUNT(*) FROM fatura_linhas) l')"
  php artisan migrate --force
  DEPOIS="$(consulta 'SELECT (SELECT COUNT(*) FROM faturas) f, (SELECT COUNT(*) FROM recibos) r, (SELECT COUNT(*) FROM fatura_linhas) l')"
  [ "$ANTES" = "$DEPOIS" ] || { echo "Contagens mudaram: $ANTES -> $DEPOIS"; exit 1; }
  verificar_triggers_e_fks
  echo "   documentos antigos intactos: $DEPOIS"
else
  echo "5/5 Ignorado (define DUMP_SQL com um dump da main para testar com dados reais)"
fi

echo "Migrations verificadas."
