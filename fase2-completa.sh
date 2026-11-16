#!/usr/bin/env bash
# Fase 2 — Segurança e multi-tenancy (SaaS1) — script ÚNICO.
# Substitui fase2-seguranca-multitenancy.sh, fase2-continuar.sh e fase2-fechar.sh
# (não corras ). Correr na branch fase2/seguranca-multitenancy:
#
#   bash fase2-completa.sh
#
# Se os testes falharem, as alterações ficam no working tree: corrige/cola-me o erro
# e volta a correr o MESMO comando — ele retoma sem repetir os patches.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

BRANCH="fase2/seguranca-multitenancy"

# --- Guarda: só corre no PR/branch certo -----------------------------------
ATUAL="$(git branch --show-current)"
if [ "$ATUAL" != "$BRANCH" ]; then
  echo "BRANCH ERRADA: estás em '$ATUAL'. Faz: git switch $BRANCH" >&2
  exit 1
fi

TRAIT="Modules/Core/app/Traits/BelongsToTenant.php"

TRACKED=(
  config/queue.php
  config/session.php
  Modules/Core/app/Http/Requests/LoginRequest.php
  Modules/Core/app/Traits/BelongsToTenant.php
  Modules/Core/config/config.php
)
NOVOS=(
  Modules/Core/tests/Feature/LoginContaDesativadaTest.php
  Modules/Core/tests/Feature/ModelosDeNegocioUsamTenantTest.php
  Modules/Core/tests/Feature/EscritasSemContextoTest.php
  docs/auditoria-fase2-seguranca-multitenancy.md
)

if grep -q "verificarEscritaSemContexto" "$TRAIT"; then
  echo ">>> Patches já aplicados nesta branch — a retomar nos testes."
else
  # --- Estado de partida: branch limpa, a partir do main -------------------
  git fetch origin main
  if ! git merge-base --is-ancestor origin/main HEAD; then
    echo "A branch $BRANCH não contém o origin/main atual. Faz: git rebase origin/main" >&2
    exit 1
  fi
  if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Há alterações em ficheiros já versionados. Faz commit/stash antes (ou muda para a branch certa)." >&2
    git status --short >&2
    exit 1
  fi
  for f in "${NOVOS[@]}"; do
    if [ -e "$f" ]; then
      echo "Já existe $f (não versionado). Move-o ou apaga-o antes de correr." >&2
      exit 1
    fi
  done

  reverter() {
    echo ">>> Um patch falhou — a repor os ficheiros alterados..." >&2
    git restore --source=HEAD --staged --worktree -- "${TRACKED[@]}" 2>/dev/null || true
    rm -f "${NOVOS[@]}"
  }
  trap reverter ERR

  # -------------------------------------------------------------------------
  # 1) Patches (cada substituição tem de acertar no número esperado de ocorrências)
  # -------------------------------------------------------------------------
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
sub("config/queue.php", "'after_commit' => false,",
    "'after_commit' => (bool) env('QUEUE_AFTER_COMMIT', true),", 4)

# Produção: cookie só por HTTPS e sessão cifrada (override por env).
sub("config/session.php", "'encrypt' => env('SESSION_ENCRYPT', false),",
    "'encrypt' => env('SESSION_ENCRYPT', env('APP_ENV') === 'production'),")
sub("config/session.php", "'secure' => env('SESSION_SECURE_COOKIE'),",
    "'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),")

# Conta desativada com palavra-passe certa passa a contar para o limitador.
sub("Modules/Core/app/Http/Requests/LoginRequest.php", "            Auth::logout();",
    "            Auth::logout();\n            RateLimiter::hit($this->chaveLimitador());")

# P1 — escritas sem tenant nem bypass: modo permitir | avisar | bloquear.
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

T = "Modules/Core/app/Traits/BelongsToTenant.php"
sub(T, "        static::updating(function ($model): void {",
    "        static::updating(function ($model): void {\n            static::verificarEscritaSemContexto($model, 'atualizar');\n")
sub(T, "        static::deleting(function ($model): void {",
    "        static::deleting(function ($model): void {\n            static::verificarEscritaSemContexto($model, 'eliminar');\n")
sub(T, "        static::creating(function ($model): void {",
    "        static::creating(function ($model): void {\n            static::verificarEscritaSemContexto($model, 'criar');\n")
sub(
    T,
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

  # -------------------------------------------------------------------------
  # 2) Testes novos
  # -------------------------------------------------------------------------
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
        \Modules\Core\Models\User::class => 'O login procura o utilizador antes de haver tenant; o isolamento é feito nos controllers.',
        \Modules\Core\Models\EmpresaServico::class => 'É lida antes de haver tenant (registo, menu); o isolamento faz-se através da Empresa.',
        \Modules\Atelier\Models\Perfil::class => 'Lido sem tenant pela landing pública; a área admin filtra por empresa_id do utilizador e exige Administrador.',
        \Modules\Atelier\Models\PortfolioItem::class => 'Lido sem tenant pela landing pública; Admin\PortfolioController filtra por empresa_id e verifica posse e papel.',
        \Modules\Faturacao\Models\AgtSubmissao::class => 'Acedida pela relação agtSubmissao() dos documentos (já com TenantScope) e por comandos/jobs entre empresas.',
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
     * funcionar (empresa, utilizador, papéis, serviços, provisionamento) e o
     * e-mail de boas-vindas não pode falhar em silêncio por causa do bloqueio.
     */
    public function test_o_registo_de_empresa_funciona_em_modo_bloquear(): void
    {
        config(['core.tenant.escritas_sem_contexto' => 'bloquear']);
        Log::spy();

        $utilizador = app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Empresa Registo Estrito', 'nif' => '100000777'],
            ['name' => 'Admin Registo', 'email' => 'admin-registo-estrito@example.test', 'password' => 'senha-segura-123'],
        );

        $this->assertDatabaseHas('empresas', ['nif' => '100000777']);
        $this->assertNotNull($utilizador->empresa_id);

        Log::shouldNotHaveReceived('warning', [
            \Mockery::on(fn ($mensagem) => str_contains((string) $mensagem, 'e-mail de boas-vindas')),
            \Mockery::any(),
        ]);
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

  # -------------------------------------------------------------------------
  # 3) Relatório da fase
  # -------------------------------------------------------------------------
  cat > docs/auditoria-fase2-seguranca-multitenancy.md <<'EOF'
# Auditoria — Fase 2: Segurança e multi-tenancy

Método: leitura do código dos módulos Core, Subscrições, Faturação (serviços de numeração e SAF-T), Armazém, Estúdio e partes do Atelier, sem ferramenta de pesquisa global. Não foi executada em MySQL. O que não foi lido está listado em «Ainda por auditar».

## Já correto (verificado no código)

- `TenantScope` é fail-closed; `TenantManager` é `scoped` e `semTenant()` restaura o estado em `finally`.
- `IdentificarTenant` limpa o estado, verifica `ativo` a cada pedido e trata super admins sem tenant; corre antes do route model binding.
- Jobs: `ProcessNotificationJob` leva o `empresaId`; o tenant e o team do Spatie são limpos entre jobs.
- `UtilizadorController`, `EmpresaController` e os FormRequests verificam empresa e papel; `is_super_admin` não é atribuível em massa.
- Webhook ProxyPay: HMAC-SHA256 do corpo original, `hash_equals`, falha fechado sem API key, evento guardado antes de responder 2xx, idempotente por hash do corpo.
- `NumeracaoService` e `SafTExportService` filtram sempre por `empresa_id` explícito.
- Armazém: inventários e stock filtram por `empresa_id` explícito, usam `lockForUpdate`, validam que a linha pertence ao inventário e à empresa; rotas com `permission:` e `throttle`.
- Portal do Estúdio: token guardado em SHA-256 com expiração, lido só da rota, cabeçalhos `no-store`/`no-referrer`/`noindex`, tenant limpo em `finally`.
- Models com `empresa_id` sem `BelongsToTenant` por decisão deliberada, agora registados e cobertos por teste: `User`, `EmpresaServico`, `Perfil` e `PortfolioItem` (Atelier; lidos sem tenant pela landing pública — a área admin filtra por `empresa_id` do utilizador, exige Administrador e limita o upload a jpg/png/webp até 4 MB) e `AgtSubmissao` (acedida pela relação `agtSubmissao()` dos documentos, que já têm TenantScope, e por comandos/jobs entre empresas).

## Corrigido neste PR

| # | Prio | Problema | Correção |
|---|------|----------|----------|
| 1 | P2 | `config/queue.php`: `after_commit` a `false` (jobs podiam correr antes do commit) | `QUEUE_AFTER_COMMIT`, por omissão `true` |
| 2 | P2 | `config/session.php`: cookie `secure` e sessão cifrada desligados por omissão | ligados por omissão em `APP_ENV=production` (override por env) |
| 3 | P2 | `LoginRequest`: conta desativada com palavra-passe certa não contava para o limitador | `RateLimiter::hit` nesse caminho + teste |
| 4 | — | Nada impedia um model novo com `empresa_id` de esquecer `BelongsToTenant` | teste `ModelosDeNegocioUsamTenantTest` (exceções justificadas) |
| 5 | P1 | `BelongsToTenant`: criar/atualizar/eliminar sem tenant nem bypass eram sempre permitidos | `core.tenant.escritas_sem_contexto` = `permitir` (testes) / `avisar` (omissão) / `bloquear` (fail-closed); testes em `EscritasSemContextoTest`, incluindo o registo público em modo `bloquear` |

## Rollout do modo de escritas sem contexto

1. Deploy com o padrão (`avisar`): nada muda para os utilizadores; os logs passam a ter «Escrita sem tenant identificado nem bypass explícito».
2. Durante uns dias, rever esses avisos (model, operação, consola/HTTP). Cada um é um fluxo legítimo a envolver em `TenantManager::semTenant()` ou a correr com tenant, ou um bug. Atenção especial: landing pública do Estúdio, webhook ProxyPay, Atelier público e jobs.
3. Sem avisos legítimos: `CORE_TENANT_ESCRITAS_SEM_CONTEXTO=bloquear` em produção.
4. Para ver o que os testes cobrem: `CORE_TENANT_ESCRITAS_SEM_CONTEXTO=bloquear php -d memory_limit=1G artisan test` (esperam-se falhas em factories que escrevem sem tenant — são informativas, não bugs de produção).

## Pendente (não alterado)

- **P2 — E-mail não verificado**: `User` implementa `MustVerifyEmail`, mas o registo autentica logo e não encontrei o middleware `verified` nas rotas lidas. Confirmar se é intencional.
- **P2 — Login**: a mensagem para conta desativada ainda distingue o motivo (só depois de acertar a palavra-passe). Decisão de produto: manter a mensagem útil ou torná-la genérica.
- **P2 — `NumeracaoService::hashDoDocumentoAnterior`**: consulta só por `serie_id` em `semTenant()`. Seguro hoje (a série vem filtrada por empresa); passar `empresaId` como defesa em profundidade.
- **P3 — `core.super_admin_emails`**: documentado como promoção a super admin no registo, mas não encontrei consumidor nos ficheiros lidos. Se existir, tem de exigir e-mail verificado; se não, remover a chave.
- **P3 — Armazém sem `servico:`**: as rotas só têm `auth`, `tenant`, `subscricao.ativa` e `permission:`; o Armazém acompanha a Faturação (que nunca se remove). Seguro hoje; acrescentar `servico:faturacao` por coerência exige adaptar os testes do Armazém.
- **P3 — Portal do Estúdio** não verifica se a empresa ainda tem o serviço `estudio` nem a subscrição ativa.
- **P3 — Landing do Estúdio** usa o `id` numérico da empresa no URL (enumerável); o Atelier usa slug.
- **P3 — Docblock de `AgtSubmissao`** diz que nunca é exposta em pedidos HTTP, mas o estado AGT parece ser mostrado na interface através de `agtSubmissao()`; o isolamento vem do documento. Não carregar essa relação em `semTenant()` sem filtrar por empresa, e corrigir o comentário.
- **P3 — `AtualizarEmpresaRequest`**: o bloqueio do NIF só considera faturas `emitida` (não recibos, notas, anuladas).
- **P3 — `TenantManager::clear()`** não repõe a flag de bypass (mitigado pelo `finally`).
- **P3 — Comentários desatualizados** em `Subscricoes/routes/web.php` e `SubscricaoController` dizem que o binding corre antes do tenant; `bootstrap/app.php` já corrige a ordem.
- **P3 — `ProcessarPagamentoConfirmadoJob`** leva o payload do gateway sem `ShouldBeEncrypted`.

## Ainda por auditar

Controllers e jobs do Atelier (além de perfil e portfólio), restantes usos de `withoutGlobalScopes()` na Faturação, seeds de permissões Spatie e concorrência nas operações financeiras fora do Armazém e da numeração.

## Produção

Servir só por HTTPS e configurar `TRUSTED_PROXIES` com os IPs do proxy. `SESSION_SECURE_COOKIE` e `SESSION_ENCRYPT` passam a `true` por omissão em `APP_ENV=production`; em staging sem HTTPS definir `SESSION_SECURE_COOKIE=false` explicitamente.
EOF

  trap - ERR
fi

# ---------------------------------------------------------------------------
# 4) Testes — só avança se passarem
# ---------------------------------------------------------------------------
[ "$(git branch --show-current)" = "$BRANCH" ] || { echo "BRANCH ERRADA antes dos testes." >&2; exit 1; }

echo ">>> Testes novos e de segurança existentes..."
php -d memory_limit=1G artisan test --filter='EscritasSemContextoTest|LoginContaDesativadaTest|ModelosDeNegocioUsamTenantTest|IsolamentoTenantTest|QueueRetryAfterTest|RegistoSessaoSeguraTest|OrdemMiddlewareTenantTest|UtilizadoresEPapeisTest|ServicosAderidosTest'

echo ">>> Suite completa (memory_limit 1G)..."
php -d memory_limit=1G artisan test

# ---------------------------------------------------------------------------
# 5) Commit, push e PR em Draft (só os ficheiros desta fase)
# ---------------------------------------------------------------------------
[ "$(git branch --show-current)" = "$BRANCH" ] || { echo "BRANCH ERRADA antes do commit." >&2; exit 1; }

FICHEIROS=("${TRACKED[@]}" "${NOVOS[@]}")

echo ">>> Outras alterações no working tree (NÃO vão para este commit):"
git status --short | grep -v -F -f <(printf '%s\n' "${FICHEIROS[@]}") || true

git add "${FICHEIROS[@]}"
git commit -m "fase 2: segurança e multi-tenancy (fila after_commit, sessão segura, limitador de login, teste de trait de tenant, escritas sem contexto em modo faseado)"
git push -u origin "$BRANCH:$BRANCH"

cat > /tmp/pr-fase2.md <<'EOF'
## Fase 2 — Segurança e multi-tenancy

Relatório completo em `docs/auditoria-fase2-seguranca-multitenancy.md`.

### Corrigido
- `config/queue.php`: `after_commit` passa a `true` (env `QUEUE_AFTER_COMMIT`).
- `config/session.php`: cookie `secure` e sessão cifrada por omissão em produção.
- `LoginRequest`: conta desativada com palavra-passe certa conta para o limitador (+ teste).
- Teste que falha se um model com `empresa_id` não usar `BelongsToTenant` (exceções justificadas: `User`, `EmpresaServico`, `Perfil`, `PortfolioItem`, `AgtSubmissao`).
- **P1 `BelongsToTenant`**: escritas sem tenant nem bypass têm agora três modos (`core.tenant.escritas_sem_contexto`): `permitir` (testes), `avisar` (omissão fora de testes, só regista) e `bloquear` (fail-closed). O registo público é testado em modo `bloquear`. Plano de rollout no relatório.

### Auditados sem problemas de segurança
Armazém (inventário/stock), Portal do Estúdio, Subscrições (webhook ProxyPay), Core (login, utilizadores, empresa).

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
