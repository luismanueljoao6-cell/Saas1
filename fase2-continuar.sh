#!/usr/bin/env bash
# Fase 2 — continuação (depois de fase2-seguranca-multitenancy.sh ter parado no teste de models).
# Correr na raiz do repositório, na branch fase2/seguranca-multitenancy:
#   bash fase2-continuar.sh
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

BRANCH="fase2/seguranca-multitenancy"

if [ "$(git rev-parse --abbrev-ref HEAD)" != "$BRANCH" ]; then
  echo "Muda primeiro para a branch $BRANCH (git checkout $BRANCH)." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# 1) Os 3 models sem a trait são decisões deliberadas: registar como exceções
#    justificadas no teste e no relatório.
# ---------------------------------------------------------------------------
python3 - <<'PY'
import pathlib

def sub(path, old, new, count=1):
    p = pathlib.Path(path)
    s = p.read_text(encoding="utf-8")
    n = s.count(old)
    if n != count:
        raise SystemExit(f"{path}: esperava {count} ocorrência(s) de {old!r}, encontrei {n}")
    p.write_text(s.replace(old, new), encoding="utf-8")

ancora_teste = "        EmpresaServico::class => 'É lida antes de haver tenant (registo, menu); o isolamento faz-se através da Empresa.',"
novas_excecoes = ancora_teste + """
        \\Modules\\Atelier\\Models\\Perfil::class => 'Lido sem tenant pela landing pública; a área admin filtra por empresa_id do utilizador e exige Administrador.',
        \\Modules\\Atelier\\Models\\PortfolioItem::class => 'Lido sem tenant pela landing pública; Admin\\\\PortfolioController filtra por empresa_id e verifica posse e papel.',
        \\Modules\\Faturacao\\Models\\AgtSubmissao::class => 'Acedida pela relação agtSubmissao() dos documentos (já com TenantScope) e por comandos/jobs entre empresas.',"""
sub("Modules/Core/tests/Feature/ModelosDeNegocioUsamTenantTest.php", ancora_teste, novas_excecoes)

sub(
    "docs/auditoria-fase2-seguranca.md",
    "\n\n## Corrigido neste PR",
    "\n- Models com `empresa_id` sem `BelongsToTenant` (decisão deliberada, agora registada e coberta pelo teste): `Perfil` e `PortfolioItem` (Atelier; lidos sem tenant pela landing pública — a área admin filtra por `empresa_id` do utilizador, exige Administrador e limita o upload a jpg/png/webp até 4 MB) e `AgtSubmissao` (acedida pela relação `agtSubmissao()` dos documentos, que já têm TenantScope, e por comandos/jobs entre empresas)."
    "\n\n## Corrigido neste PR",
)

sub(
    "docs/auditoria-fase2-seguranca.md",
    "- **P3 — Comentários desatualizados**",
    "- **P3 — Docblock de `AgtSubmissao`** diz que nunca é exposta em pedidos HTTP, mas o estado AGT parece ser mostrado na interface através de `agtSubmissao()`; o isolamento vem do documento. Não carregar essa relação em `semTenant()` sem filtrar por empresa, e corrigir o comentário.\n"
    "- **P3 — Comentários desatualizados**",
)
PY

# ---------------------------------------------------------------------------
# 2) Testes — só avança se passarem
# ---------------------------------------------------------------------------
echo ">>> A correr os testes novos e os testes de segurança existentes..."
php artisan test --filter='LoginContaDesativadaTest|ModelosDeNegocioUsamTenantTest|IsolamentoTenantTest|QueueRetryAfterTest|RegistoSessaoSeguraTest|OrdemMiddlewareTenantTest|UtilizadoresEPapeisTest'

echo ">>> A correr a suite completa..."
php artisan test

# ---------------------------------------------------------------------------
# 3) Commit, push e PR em Draft
# ---------------------------------------------------------------------------
git add config/queue.php config/session.php \
  Modules/Core/app/Http/Requests/LoginRequest.php \
  Modules/Core/tests/Feature/LoginContaDesativadaTest.php \
  Modules/Core/tests/Feature/ModelosDeNegocioUsamTenantTest.php \
  docs/auditoria-fase2-seguranca.md

git commit -m "fase 2: segurança e multi-tenancy (queue after_commit, sessão segura, limitador de login, teste de trait de tenant)"
git push -u origin "$BRANCH"

cat > /tmp/pr-fase2.md <<'EOF'
## Fase 2 — Segurança e multi-tenancy

Relatório completo em `docs/auditoria-fase2-seguranca.md`.

### Corrigido
- `config/queue.php`: `after_commit` passa a `true` (env `QUEUE_AFTER_COMMIT`).
- `config/session.php`: cookie `secure` e sessão cifrada por omissão em produção.
- `LoginRequest`: conta desativada com palavra-passe certa conta para o limitador (+ teste).
- Novo teste que falha se um model com `empresa_id` não usar `BelongsToTenant`. Exceções justificadas: `User`, `EmpresaServico`, `Perfil`, `PortfolioItem`, `AgtSubmissao`.

### Pendente (documentado, não alterado)
- P1: escritas sem tenant/bypass em `BelongsToTenant` (requer ajustar o registo público).
- P2: mensagem de login para conta desativada; `empresaId` em `hashDoDocumentoAnterior`.
- Por auditar: Estúdio, Armazém, restantes `withoutGlobalScopes()`, permissões Spatie.

### Verificação
Testes novos + suite completa executados no Codespace antes do push.
EOF

if command -v gh >/dev/null 2>&1; then
  gh pr create --draft --base main --head "$BRANCH" \
    --title "Fase 2: segurança e multi-tenancy" \
    --body-file /tmp/pr-fase2.md
else
  echo "gh não encontrado: abre o PR (Draft) em https://github.com/luismanueljoao6-cell/Saas1/pull/new/$BRANCH"
fi

echo ">>> Fase 2 (parte 1) entregue."
