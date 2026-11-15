#!/usr/bin/env bash
# Fase 2 — Segurança e multi-tenancy (SaaS1)
# Correr na raiz do repositório, no Codespace:  bash fase2-seguranca-multitenancy.sh
#
# O que faz:
#   1. cria a branch fase2/seguranca-multitenancy a partir de origin/main
#   2. config/queue.php   -> after_commit passa a true (QUEUE_AFTER_COMMIT)
#   3. config/session.php -> cookie secure + sessão cifrada por omissão em produção
#   4. LoginRequest       -> conta desativada com palavra-passe certa também conta
#                            para o limitador de tentativas
#   5. adiciona 2 testes (login de conta desativada; todos os models com
#      empresa_id usam BelongsToTenant) e o relatório docs/auditoria-fase2-seguranca.md
#   6. corre os testes; só se passarem faz commit, push e abre o PR em Draft
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

BRANCH="fase2/seguranca-multitenancy"

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "Há alterações por commitar. Faz commit ou stash antes de correr este script." >&2
  exit 1
fi

git fetch origin main
git checkout -B "$BRANCH" origin/main

# ---------------------------------------------------------------------------
# 1) Alterações em ficheiros existentes (cada substituição tem de acertar
#    exatamente no número de ocorrências esperado, senão o script pára).
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

# Jobs despachados dentro de transações só chegam à fila depois do commit.
sub(
    "config/queue.php",
    "'after_commit' => false,",
    "'after_commit' => (bool) env('QUEUE_AFTER_COMMIT', true),",
    4,
)

# Produção: cookie só por HTTPS e sessão cifrada, salvo override explícito por env.
sub(
    "config/session.php",
    "'encrypt' => env('SESSION_ENCRYPT', false),",
    "'encrypt' => env('SESSION_ENCRYPT', env('APP_ENV') === 'production'),",
)
sub(
    "config/session.php",
    "'secure' => env('SESSION_SECURE_COOKIE'),",
    "'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),",
)

# Conta desativada com palavra-passe certa passa a contar para o limitador.
sub(
    "Modules/Core/app/Http/Requests/LoginRequest.php",
    "            Auth::logout();",
    "            Auth::logout();\n            RateLimiter::hit($this->chaveLimitador());",
)
PY

# ---------------------------------------------------------------------------
# 2) Testes novos
# ---------------------------------------------------------------------------
cat > Modules/Core/tests/Feature/LoginContaDesativadaTest.php <<'EOF'
<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Tests\TestCase;

class LoginContaDesativadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_conta_desativada_com_palavra_passe_certa_conta_para_o_limitador(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa Desativada', 'nif' => '100000501']);

        User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Conta desativada',
            'email' => 'desativada@example.test',
            'password' => 'palavra-passe-certa',
            'ativo' => false,
        ]);

        $credenciais = ['email' => 'desativada@example.test', 'password' => 'palavra-passe-certa'];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', $credenciais)->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->post('/login', $credenciais)->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Demasiadas tentativas',
            (string) session('errors')->first('email'),
        );
        $this->assertGuest();
    }
}
EOF

cat > Modules/Core/tests/Feature/ModelosDeNegocioUsamTenantTest.php <<'EOF'
<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\EmpresaServico;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;
use ReflectionClass;
use Tests\TestCase;

/**
 * Rede de segurança contra o esquecimento da trait: qualquer model dos
 * módulos cuja tabela tenha `empresa_id` tem de usar BelongsToTenant, a
 * menos que esteja nas exceções abaixo (cada uma com a sua justificação).
 * Um model novo sem a trait falha aqui em vez de vazar dados entre empresas.
 */
class ModelosDeNegocioUsamTenantTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<class-string, string> */
    private const EXCECOES = [
        User::class => 'O login procura o utilizador antes de haver tenant; o isolamento é feito nos controllers.',
        EmpresaServico::class => 'É lida antes de haver tenant (registo, menu); o isolamento faz-se através da Empresa.',
    ];

    public function test_todo_o_model_com_empresa_id_usa_belongs_to_tenant(): void
    {
        $semTrait = [];
        $analisados = 0;

        foreach ($this->modelosDosModulos() as $classe) {
            $modelo = new $classe;

            if (! Schema::hasColumn($modelo->getTable(), 'empresa_id')) {
                continue;
            }

            $analisados++;

            if (array_key_exists($classe, self::EXCECOES)) {
                continue;
            }

            if (! in_array(BelongsToTenant::class, class_uses_recursive($classe), true)) {
                $semTrait[] = $classe;
            }
        }

        $this->assertGreaterThan(0, $analisados, 'Nenhum model com empresa_id foi analisado — a descoberta de models falhou.');

        $this->assertSame(
            [],
            $semTrait,
            "Models com empresa_id sem a trait BelongsToTenant (usa a trait ou justifica em EXCECOES):\n".implode("\n", $semTrait)
        );
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelosDosModulos(): array
    {
        $classes = [];

        foreach (glob(base_path('Modules/*/app/Models/*.php')) ?: [] as $ficheiro) {
            $modulo = basename(dirname($ficheiro, 3));
            $classe = "Modules\\{$modulo}\\Models\\".basename($ficheiro, '.php');

            if (! class_exists($classe) || ! is_subclass_of($classe, Model::class)) {
                continue;
            }

            if ((new ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            $classes[] = $classe;
        }

        return $classes;
    }
}
EOF

# ---------------------------------------------------------------------------
# 3) Relatório da fase
# ---------------------------------------------------------------------------
cat > docs/auditoria-fase2-seguranca.md <<'EOF'
# Auditoria — Fase 2: Segurança e multi-tenancy

## Já correto (verificado no código)

- `TenantScope` é fail-closed; `TenantManager` é `scoped` e `semTenant()` restaura o estado em `finally`.
- `IdentificarTenant` limpa o estado, verifica `ativo` a cada pedido e trata super admins sem tenant; corre antes do route model binding.
- Jobs: `ProcessNotificationJob` leva o `empresaId`; o tenant e o team do Spatie são limpos entre jobs.
- `UtilizadorController`, `EmpresaController` e os FormRequests verificam empresa e papel; `is_super_admin` não é atribuível em massa.
- Webhook ProxyPay: HMAC-SHA256 do corpo original, `hash_equals`, falha fechado sem API key, evento guardado antes de responder 2xx, idempotente por hash do corpo.
- Serviços da Faturação auditados (`NumeracaoService`, `SafTExportService`) filtram sempre por `empresa_id` explícito.

## Corrigido neste PR

| # | Prio | Problema | Correção |
|---|------|----------|----------|
| 1 | P2 | `config/queue.php`: `after_commit` a `false` (jobs podiam correr antes do commit) | `QUEUE_AFTER_COMMIT`, por omissão `true` |
| 2 | P2 | `config/session.php`: cookie `secure` e sessão cifrada desligados por omissão | ligados por omissão em `APP_ENV=production` (override por env) |
| 3 | P2 | `LoginRequest`: conta desativada com palavra-passe certa não contava para o limitador | `RateLimiter::hit` nesse caminho + teste |
| 4 | — | Nada impedia um model novo com `empresa_id` de esquecer `BelongsToTenant` | teste `ModelosDeNegocioUsamTenantTest` (exceções: `User`, `EmpresaServico`, justificadas) |

## Pendente (não alterado de propósito)

- **P1 — `BelongsToTenant`**: criar/atualizar/eliminar são permitidos sem tenant e sem bypass.
  Apertar à cegas parte o registo público (`RegistoEmpresaService` corre sem tenant) e os testes.
  Plano: envolver o registo e os provisioners em `semTenant()`/tenant da nova empresa, depois
  lançar `LogicException` em escritas sem contexto, com a suite completa a validar.
- **P2 — Login**: a mensagem para conta desativada ainda distingue o motivo (só depois de acertar a palavra-passe). Decisão de produto: manter a mensagem útil ou torná-la genérica.
- **P2 — `NumeracaoService::hashDoDocumentoAnterior`**: consulta só por `serie_id` em `semTenant()`. Seguro hoje (a série vem filtrada por empresa); passar `empresaId` como defesa em profundidade.
- **P3 — `AtualizarEmpresaRequest`**: o bloqueio do NIF só considera faturas `emitida` (não recibos, notas, anuladas).
- **P3 — `TenantManager::clear()`** não repõe a flag de bypass (mitigado pelo `finally`).
- **P3 — Comentários desatualizados** em `Subscricoes/routes/web.php` e `SubscricaoController` dizem que o binding corre antes do tenant; `bootstrap/app.php` já corrige a ordem.
- **P3 — `ProcessarPagamentoConfirmadoJob`** leva o payload do gateway sem `ShouldBeEncrypted`.

## Ainda por auditar

Controllers/uploads/jobs de Atelier, Estúdio e Armazém; restantes usos de `withoutGlobalScopes()` na Faturação; seeds de permissões Spatie; concorrência nas operações financeiras.

## Produção

Definir `SESSION_SECURE_COOKIE=true` (já é o padrão em production), servir só por HTTPS e `TRUSTED_PROXIES` com os IPs do proxy. Em ambientes de staging sem HTTPS, `SESSION_SECURE_COOKIE=false` explícito.
EOF

# ---------------------------------------------------------------------------
# 4) Testes — só avança se passarem
# ---------------------------------------------------------------------------
echo ">>> A correr os testes novos e os testes de segurança existentes..."
php artisan test --filter='LoginContaDesativadaTest|ModelosDeNegocioUsamTenantTest|IsolamentoTenantTest|QueueRetryAfterTest|RegistoSessaoSeguraTest|OrdemMiddlewareTenantTest|UtilizadoresEPapeisTest'

echo ">>> A correr a suite completa..."
php artisan test

# ---------------------------------------------------------------------------
# 5) Commit, push e PR em Draft
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
- Novo teste que falha se um model com `empresa_id` não usar `BelongsToTenant`.

### Pendente (documentado, não alterado)
- P1: escritas sem tenant/bypass em `BelongsToTenant` (requer ajustar o registo público).
- P2: mensagem de login para conta desativada; `empresaId` em `hashDoDocumentoAnterior`.
- Por auditar: Atelier, Estúdio, Armazém, restantes `withoutGlobalScopes()`, permissões Spatie.

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
