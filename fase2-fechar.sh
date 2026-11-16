#!/usr/bin/env bash
# Fase 2 — fecho: P1 do BelongsToTenant + relatório final + commit/push/PR.
# Correr DENTRO da branch fase2/seguranca-multitenancy:
#   bash fase2-fechar.sh
#
# Pressupõe que fase2-seguranca-multitenancy.sh e o passo 1 do fase2-continuar.sh
# já foram aplicados (alterações por commitar nesta branch). NÃO voltes a correr
# esses dois scripts.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

BRANCH="fase2/seguranca-multitenancy"

# --- Guarda: só corre no PR/branch certo -----------------------------------
ATUAL="$(git branch --show-current)"
if [ "$ATUAL" != "$BRANCH" ]; then
  echo "BRANCH ERRADA: estás em '$ATUAL'. Faz: git switch $BRANCH" >&2
  exit 1
fi

# As alterações anteriores da fase 2 têm de estar presentes (ou já commitadas).
for f in \
  Modules/Core/tests/Feature/LoginContaDesativadaTest.php \
  Modules/Core/tests/Feature/ModelosDeNegocioUsamTenantTest.php \
  docs/auditoria-fase2-seguranca.md; do
  [ -f "$f" ] || { echo "Falta $f — a parte 1 da fase 2 não está nesta branch." >&2; exit 1; }
done

# ---------------------------------------------------------------------------
# 1) P1 — BelongsToTenant: escritas sem tenant nem bypass
#    Modo em core.tenant.escritas_sem_contexto:
#      permitir (testes) | avisar (omissão fora de testes) | bloquear (fail-closed)
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


# Idempotência: se o patch já foi aplicado, não repete.
trait = pathlib.Path("Modules/Core/app/Traits/BelongsToTenant.php")
if "verificarEscritaSemContexto" in trait.read_text(encoding="utf-8"):
    raise SystemExit("O patch do BelongsToTenant já está aplicado — a sair sem alterar nada.")

# --- config ---
sub(
    "Modules/Core/config/config.php",
    "    'guard' => env('CORE_AUTH_GUARD', 'web'),",
    r"""    'guard' => env('CORE_AUTH_GUARD', 'web'),

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy — escritas sem contexto de empresa
    |--------------------------------------------------------------------------
    |
    | O que fazer quando um model com BelongsToTenant é criado, atualizado ou
    | eliminado sem tenant identificado E fora de um bypass explícito
    | (TenantManager::semTenant()):
    |
    |   permitir — comportamento antigo (silencioso). Por omissão nos testes.
    |   avisar   — regista um aviso com o model e a operação. Por omissão fora
    |              de testes: serve para descobrir fluxos que escrevem sem contexto.
    |   bloquear — lança LogicException (fail-closed). Usar em produção depois
    |              de uma fase em 'avisar' sem avisos legítimos nos logs.
    |
    */
    'tenant' => [
        'escritas_sem_contexto' => env(
            'CORE_TENANT_ESCRITAS_SEM_CONTEXTO',
            env('APP_ENV') === 'testing' ? 'permitir' : 'avisar'
        ),
    ],""",
)

# --- trait ---
sub(
    "Modules/Core/app/Traits/BelongsToTenant.php",
    "        static::updating(function ($model): void {",
    "        static::updating(function ($model): void {\n            static::verificarEscritaSemContexto($model, 'atualizar');\n",
)
sub(
    "Modules/Core/app/Traits/BelongsToTenant.php",
    "        static::deleting(function ($model): void {",
    "        static::deleting(function ($model): void {\n            static::verificarEscritaSemContexto($model, 'eliminar');\n",
)
sub(
    "Modules/Core/app/Traits/BelongsToTenant.php",
    "        static::creating(function ($model): void {",
    "        static::creating(function ($model): void {\n            static::verificarEscritaSemContexto($model, 'criar');\n",
)
sub(
    "Modules/Core/app/Traits/BelongsToTenant.php",
    "    public function empresa(): BelongsTo",
    r"""    /**
     * Escrita (criar, atualizar, eliminar) sem tenant identificado e fora de um
     * bypass explícito. O comportamento depende de core.tenant.escritas_sem_contexto:
     * 'permitir' ignora, 'avisar' regista um aviso e 'bloquear' lança exceção.
     * Qualquer outro valor é tratado como 'avisar'.
     */
    protected static function verificarEscritaSemContexto($model, string $operacao): void
    {
        $tenantManager = app(TenantManager::class);

        if ($tenantManager->has() || $tenantManager->emBypass()) {
            return;
        }

        $modo = (string) config('core.tenant.escritas_sem_contexto', 'avisar');

        if ($modo === 'permitir') {
            return;
        }

        if ($modo === 'bloquear') {
            throw new \LogicException(sprintf(
                'Escrita (%s) em %s sem tenant identificado nem bypass explícito (TenantManager::semTenant()).',
                $operacao,
                $model::class,
            ));
        }

        Log::warning('Escrita sem tenant identificado nem bypass explícito.', [
            'model' => $model::class,
            'operacao' => $operacao,
            'empresa_id' => $model->getAttribute('empresa_id'),
            'consola' => app()->runningInConsole(),
        ]);
    }

    public function empresa(): BelongsTo""",
)
PY

# ---------------------------------------------------------------------------
# 2) Teste do P1
# ---------------------------------------------------------------------------
cat > Modules/Core/tests/Feature/EscritasSemContextoTest.php <<'EOF'
<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;
use Modules\Core\Traits\BelongsToTenant;
use Tests\TestCase;

/**
 * Escritas em models com BelongsToTenant sem tenant e sem bypass explícito.
 * Modos: permitir | avisar | bloquear (config core.tenant.escritas_sem_contexto).
 */
class EscritasSemContextoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('registos_teste_escrita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nome');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('registos_teste_escrita');

        parent::tearDown();
    }

    private function empresa(string $nif = '100000601'): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Empresa Escritas', 'nif' => $nif]);
    }

    public function test_o_modo_configurado_e_um_valor_conhecido(): void
    {
        $this->assertContains(
            config('core.tenant.escritas_sem_contexto'),
            ['permitir', 'avisar', 'bloquear'],
        );
    }

    public function test_bloquear_recusa_criar_sem_contexto(): void
    {
        $empresa = $this->empresa();
        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);

        $this->expectException(LogicException::class);
        RegistoTesteEscrita::create(['empresa_id' => $empresa->id, 'nome' => 'sem contexto']);
    }

    public function test_bloquear_recusa_atualizar_sem_contexto(): void
    {
        $empresa = $this->empresa();
        $tenant = app(TenantManager::class);

        $tenant->set($empresa->id);
        $registo = RegistoTesteEscrita::create(['nome' => 'original']);
        $tenant->clear();

        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);

        $this->expectException(LogicException::class);
        $registo->update(['nome' => 'alterado']);
    }

    public function test_bloquear_recusa_eliminar_sem_contexto(): void
    {
        $empresa = $this->empresa();
        $tenant = app(TenantManager::class);

        $tenant->set($empresa->id);
        $registo = RegistoTesteEscrita::create(['nome' => 'original']);
        $tenant->clear();

        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);

        $this->expectException(LogicException::class);
        $registo->delete();
    }

    public function test_bloquear_deixa_escrever_com_tenant_ou_em_bypass_explicito(): void
    {
        $empresa = $this->empresa();
        $tenant = app(TenantManager::class);
        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);

        $tenant->set($empresa->id);
        $registo = RegistoTesteEscrita::create(['nome' => 'com tenant']);
        $registo->update(['nome' => 'com tenant, atualizado']);
        $tenant->clear();

        $tenant->semTenant(function () use ($registo, $empresa): void {
            $registo->update(['nome' => 'em bypass']);
            RegistoTesteEscrita::create(['empresa_id' => $empresa->id, 'nome' => 'criado em bypass']);
            $registo->delete();
        });

        $this->assertDatabaseHas('registos_teste_escrita', ['nome' => 'criado em bypass']);
        $this->assertDatabaseMissing('registos_teste_escrita', ['id' => $registo->id]);
    }

    public function test_avisar_regista_um_aviso_mas_deixa_escrever(): void
    {
        $empresa = $this->empresa();
        config(['core.tenant.escritas_sem_contexto' => 'avisar']);
        Log::spy();

        RegistoTesteEscrita::create(['empresa_id' => $empresa->id, 'nome' => 'sem contexto']);

        $this->assertDatabaseHas('registos_teste_escrita', ['nome' => 'sem contexto']);
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($mensagem, $contexto = []) => ($contexto['operacao'] ?? null) === 'criar'
                && ($contexto['model'] ?? null) === RegistoTesteEscrita::class
        );
    }

    public function test_permitir_mantem_o_comportamento_antigo(): void
    {
        $empresa = $this->empresa();
        config(['core.tenant.escritas_sem_contexto' => 'permitir']);

        RegistoTesteEscrita::create(['empresa_id' => $empresa->id, 'nome' => 'sem contexto']);

        $this->assertDatabaseHas('registos_teste_escrita', ['nome' => 'sem contexto']);
    }

    /**
     * O registo público corre sem tenant: em modo 'bloquear' continua a ter de
     * funcionar (empresa, utilizador, papéis, serviços e provisionamento).
     */
    public function test_o_registo_de_empresa_funciona_em_modo_bloquear(): void
    {
        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);

        $utilizador = app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Empresa Registo Estrito', 'nif' => '100000777'],
            ['name' => 'Admin Registo', 'email' => 'admin-registo-estrito@example.test', 'password' => 'senha-segura-123'],
        );

        $this->assertDatabaseHas('empresas', ['nif' => '100000777']);
        $this->assertNotNull($utilizador->empresa_id);
    }
}

/**
 * Model de apoio só para este teste (mesmo padrão do IsolamentoTenantTest).
 */
class RegistoTesteEscrita extends Model
{
    use BelongsToTenant;

    protected $table = 'registos_teste_escrita';

    protected $fillable = ['nome', 'empresa_id'];
}
EOF

# ---------------------------------------------------------------------------
# 3) Relatório: P1 tratado + resultado da auditoria de Armazém e Estúdio
# ---------------------------------------------------------------------------
python3 - <<'PY'
import pathlib
import re

p = pathlib.Path("docs/auditoria-fase2-seguranca.md")
s = p.read_text(encoding="utf-8")


def uma_vez(padrao, repl, flags=0):
    global s
    s, n = re.subn(padrao, lambda m: repl(m) if callable(repl) else repl, s, count=1, flags=flags)
    if n != 1:
        raise SystemExit(f"docs: padrão não encontrado exatamente uma vez: {padrao!r}")


# P1: sai de "Pendente" (é tratado neste PR).
uma_vez(r"- \*\*P1 — `BelongsToTenant`\*\*.*?(?=\n- \*\*P2)", "- **P1 — `BelongsToTenant`**: tratado neste PR em modo faseado — ver a tabela acima e «Rollout» abaixo.", re.S)

# Tabela: linha 5.
uma_vez(
    r"(\| 4 \| — \|[^\n]*\n)",
    lambda m: m.group(1)
    + "| 5 | P1 | `BelongsToTenant`: criar/atualizar/eliminar sem tenant nem bypass eram sempre permitidos | `core.tenant.escritas_sem_contexto` = `permitir` (testes) / `avisar` (omissão) / `bloquear` (fail-closed); testes em `EscritasSemContextoTest`, incluindo o registo público em modo `bloquear` |\n",
)

# Já correto: Armazém e Portal do Estúdio.
uma_vez(
    r"\n\n## Corrigido neste PR",
    "\n- Armazém: inventários e stock filtram por `empresa_id` explícito, usam `lockForUpdate`, validam que a linha pertence ao inventário e à empresa, e as rotas têm `permission:` e `throttle`."
    "\n- Portal do Estúdio: token guardado em SHA-256 com expiração, lido só da rota, cabeçalhos `no-store`/`no-referrer`/`noindex` e tenant limpo em `finally`."
    "\n\n## Corrigido neste PR",
)

# Pendências novas.
uma_vez(
    r"- \*\*P3 — Docblock de `AgtSubmissao`\*\*",
    "- **P2 — E-mail não verificado**: `User` implementa `MustVerifyEmail`, mas o registo autentica logo e não encontrei o middleware `verified` nas rotas lidas. Confirmar se é intencional.\n"
    "- **P3 — `core.super_admin_emails`** está documentado como promoção a super admin no registo, mas não encontrei consumidor nos ficheiros lidos. Se existir, tem de exigir e-mail verificado; se não, remover a chave.\n"
    "- **P3 — Armazém sem `servico:`**: as rotas só têm `auth`, `tenant`, `subscricao.ativa` e `permission:`; o Armazém acompanha a Faturação (que nunca se remove). Seguro hoje; acrescentar `servico:faturacao` por coerência exige adaptar os testes do Armazém.\n"
    "- **P3 — Portal do Estúdio** não verifica se a empresa ainda tem o serviço `estudio` nem a subscrição ativa.\n"
    "- **P3 — Landing do Estúdio** usa o `id` numérico da empresa no URL (enumerável); o Atelier usa slug.\n"
    "- **P3 — Docblock de `AgtSubmissao`**",
)

# Por auditar.
uma_vez(
    r"## Ainda por auditar\n\n[^\n]*\n",
    "## Ainda por auditar\n\nControllers e jobs do Atelier (além de perfil e portfólio), restantes usos de `withoutGlobalScopes()` na Faturação, seeds de permissões Spatie e concorrência nas operações financeiras fora do Armazém e da numeração. A auditoria foi feita por leitura do código, sem ferramenta de pesquisa global.\n",
)

s = s.rstrip("\n") + """

## Rollout do modo de escritas sem contexto

1. Deploy com o padrão (`avisar`): nada muda para os utilizadores; os logs passam a ter «Escrita sem tenant identificado nem bypass explícito».
2. Durante uns dias, rever esses avisos (model, operação, consola/HTTP). Cada um é um fluxo legítimo a envolver em `TenantManager::semTenant()` ou a correr com tenant, ou um bug.
3. Sem avisos legítimos: `CORE_TENANT_ESCRITAS_SEM_CONTEXTO=bloquear` em produção.
4. Para descobrir fluxos cobertos por testes: `CORE_TENANT_ESCRITAS_SEM_CONTEXTO=bloquear php -d memory_limit=1G artisan test` (esperam-se falhas em factories que escrevem sem tenant — são informativas, não bugs de produção).
"""

p.write_text(s, encoding="utf-8")
PY

# ---------------------------------------------------------------------------
# 4) Testes — só avança se passarem
# ---------------------------------------------------------------------------
echo ">>> Testes novos e de segurança existentes..."
php -d memory_limit=1G artisan test --filter='EscritasSemContextoTest|LoginContaDesativadaTest|ModelosDeNegocioUsamTenantTest|IsolamentoTenantTest|QueueRetryAfterTest|RegistoSessaoSeguraTest|OrdemMiddlewareTenantTest|UtilizadoresEPapeisTest|ServicosAderidosTest'

echo ">>> Suite completa (memory_limit 1G)..."
php -d memory_limit=1G artisan test

# ---------------------------------------------------------------------------
# 5) Commit, push e PR em Draft (só os ficheiros desta fase)
# ---------------------------------------------------------------------------
[ "$(git branch --show-current)" = "$BRANCH" ] || { echo "BRANCH ERRADA antes do commit." >&2; exit 1; }

FICHEIROS=(
  config/queue.php
  config/session.php
  Modules/Core/app/Http/Requests/LoginRequest.php
  Modules/Core/app/Traits/BelongsToTenant.php
  Modules/Core/config/config.php
  Modules/Core/tests/Feature/LoginContaDesativadaTest.php
  Modules/Core/tests/Feature/ModelosDeNegocioUsamTenantTest.php
  Modules/Core/tests/Feature/EscritasSemContextoTest.php
  docs/auditoria-fase2-seguranca.md
)

echo ">>> Outras alterações no working tree (NÃO vão para este commit):"
git status --short | grep -v -F -f <(printf '%s\n' "${FICHEIROS[@]}") || true

git add "${FICHEIROS[@]}"
git commit -m "fase 2: segurança e multi-tenancy (fila after_commit, sessão segura, limitador de login, teste de trait de tenant, escritas sem contexto em modo faseado)"
git push -u origin "$BRANCH"

cat > /tmp/pr-fase2.md <<'EOF'
## Fase 2 — Segurança e multi-tenancy

Relatório completo em `docs/auditoria-fase2-seguranca.md`.

### Corrigido
- `config/queue.php`: `after_commit` passa a `true` (env `QUEUE_AFTER_COMMIT`).
- `config/session.php`: cookie `secure` e sessão cifrada por omissão em produção.
- `LoginRequest`: conta desativada com palavra-passe certa conta para o limitador (+ teste).
- Teste que falha se um model com `empresa_id` não usar `BelongsToTenant` (exceções justificadas: `User`, `EmpresaServico`, `Perfil`, `PortfolioItem`, `AgtSubmissao`).
- **P1 `BelongsToTenant`**: escritas sem tenant nem bypass têm agora três modos (`core.tenant.escritas_sem_contexto`): `permitir` (testes), `avisar` (omissão fora de testes, só regista) e `bloquear` (fail-closed). O registo público é testado em modo `bloquear`. Plano de rollout no relatório.

### Auditados sem problemas de segurança
Armazém (inventário/stock), Portal do Estúdio, módulo Subscrições (webhook ProxyPay), Core (login, utilizadores, empresa).

### Pendente (documentado)
P2 e-mail não verificado; P2 mensagem de login para conta desativada; P2 `empresaId` em `hashDoDocumentoAnterior`; vários P3. Por auditar: Atelier (restante), `withoutGlobalScopes()` na Faturação, seeds Spatie.

### Verificação
Testes novos e suite completa executados no Codespace antes do push.
EOF

if gh pr view "$BRANCH" >/dev/null 2>&1; then
  echo "O PR já existe — o push atualizou-o."
elif command -v gh >/dev/null 2>&1; then
  gh pr create --draft --base main --head "$BRANCH" \
    --title "Fase 2: segurança e multi-tenancy" \
    --body-file /tmp/pr-fase2.md
else
  echo "gh não encontrado: abre o PR (Draft) em https://github.com/luismanueljoao6-cell/Saas1/pull/new/$BRANCH"
fi

echo ">>> Fase 2 entregue."
