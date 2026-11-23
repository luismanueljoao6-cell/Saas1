#!/usr/bin/env bash
# CORE-03 (Autenticação) — PARTE C, passo 2b
# Liga o login ao desafio de 2FA: o /login deixa de abrir sessão quando a
# conta tem 2FA ativo; guarda o estado pendente e redireciona para o desafio.
#
# Mesma branch (core/03-autenticacao), mesmo histórico: só acrescenta um commit.
# Correr no terminal do Codespaces, na raiz do repositório:
#   bash core03-parteC-passo2b-ligar-login-2fa.sh
set -euo pipefail

BRANCH_ALVO="core/03-autenticacao"
COMMIT_PASSO2="7fb0589"   # commit do desafio de 2FA (tem de estar na branch)

falhar() { echo "❌ $*" >&2; exit 1; }
escrever() { mkdir -p "$(dirname "$1")"; cat > "$1"; echo "  + $1"; }

# ---------------------------------------------------------------------------
# 0. Verificação de repositório, branch e PR (SEMPRE antes de tocar em ficheiros)
# ---------------------------------------------------------------------------
echo "== 0. Verificação de repositório, branch e PR =="

raiz="$(git rev-parse --show-toplevel 2>/dev/null)" || falhar "Isto não é um repositório git."
cd "$raiz"
[ -f artisan ] && [ -d Modules/Core ] || falhar "Corre isto na raiz do Saas1 (não encontrei artisan e Modules/Core)."
git remote get-url origin | grep -qi 'luismanueljoao6-cell/Saas1' \
  || falhar "O remote 'origin' não é luismanueljoao6-cell/Saas1."

git fetch origin --prune --quiet
git show-ref --verify --quiet "refs/remotes/origin/$BRANCH_ALVO" \
  || falhar "origin/$BRANCH_ALVO não existe no GitHub."

atual="$(git rev-parse --abbrev-ref HEAD)"
if [ "$atual" != "$BRANCH_ALVO" ]; then
  [ -z "$(git status --porcelain)" ] \
    || falhar "Estás em '$atual' com alterações por commitar. Faz commit/stash antes de correr isto."
  if git show-ref --verify --quiet "refs/heads/$BRANCH_ALVO"; then
    git checkout "$BRANCH_ALVO"
  else
    git checkout -b "$BRANCH_ALVO" "origin/$BRANCH_ALVO"
  fi
fi
[ "$(git rev-parse --abbrev-ref HEAD)" = "$BRANCH_ALVO" ] || falhar "Não estou em $BRANCH_ALVO."

# Sincronização com o GitHub (sem criar merges nem apagar nada).
if [ "$(git rev-parse HEAD)" != "$(git rev-parse "origin/$BRANCH_ALVO")" ]; then
  if git merge-base --is-ancestor HEAD "origin/$BRANCH_ALVO"; then
    echo "↻ A branch local está atrás do GitHub: avanço (fast-forward)."
    git merge --ff-only "origin/$BRANCH_ALVO"
  elif git merge-base --is-ancestor "origin/$BRANCH_ALVO" HEAD; then
    echo "ℹ Tens commits locais ainda não enviados; mantenho-os."
  else
    falhar "A branch local e o GitHub divergiram. Não avanço: diz-me o 'git log --oneline -5' de cada lado."
  fi
fi

git cat-file -e "${COMMIT_PASSO2}^{commit}" 2>/dev/null \
  && git merge-base --is-ancestor "$COMMIT_PASSO2" HEAD \
  || falhar "A branch não contém o commit do desafio de 2FA ($COMMIT_PASSO2)."

[ -z "$(git status --porcelain)" ] \
  || falhar "Há alterações por commitar. Este passo vai num commit só dele: faz commit/stash primeiro."

if command -v gh >/dev/null 2>&1; then
  pr="$(gh pr list --head "$BRANCH_ALVO" --state open --json number,baseRefName --jq '.[0] | "#\(.number) → \(.baseRefName)"' 2>/dev/null || true)"
  if [ -n "$pr" ] && [ "$pr" != "null" ]; then
    echo "✔ PR aberto desta branch: $pr (o push atualiza-o)"
  else
    echo "ℹ Ainda não há PR aberto para $BRANCH_ALVO (base prevista: core/02-multitenancy)."
  fi
fi
echo "✔ Branch: $BRANCH_ALVO @ $(git rev-parse --short HEAD)"

# Guarda de integridade: só substituo os ficheiros se forem exatamente os que li.
verificar_blob() {
  local caminho="$1" esperado="$2" atual_blob
  atual_blob="$(git rev-parse "HEAD:$caminho")"
  [ "$atual_blob" = "$esperado" ] \
    || falhar "$caminho mudou desde que o li (esperado $esperado, atual $atual_blob). Não sobrescrevo; diz-me."
}
verificar_blob Modules/Core/app/Http/Requests/LoginRequest.php b47e5dbc2d1be6cf65282dd97d2dd57d370ba036
verificar_blob Modules/Core/app/Http/Controllers/Auth/AuthenticatedSessionController.php a60ea62ed910f129c0134fec79cd46b8e148fb4a

# O método autenticar() vai ser substituído por validarCredenciais(): ninguém mais o pode chamar.
if grep -rn --include='*.php' -- '->autenticar(' Modules app routes tests 2>/dev/null \
   | grep -v 'Modules/Core/app/Http/Controllers/Auth/AuthenticatedSessionController.php'; then
  falhar "Há mais chamadas a ->autenticar() (acima). Não avanço sem as tratar."
fi

[ -d vendor ] || composer install --no-interaction

# ---------------------------------------------------------------------------
# 1. Correção
# ---------------------------------------------------------------------------
echo "== 1. Correção =="

escrever Modules/Core/app/Http/Requests/LoginRequest.php <<'PHP'
<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Valida as credenciais SEM abrir sessão e devolve o utilizador.
     *
     * Quem decide o que fazer a seguir é o controlador: abrir a sessão já ou
     * pedir primeiro o código de 2FA. Só aqui se conta o limite de tentativas
     * da palavra-passe; o do código de 2FA vive no DesafioDoisFatoresController.
     *
     * Credenciais inválidas e conta desativada têm mensagens diferentes, mas a
     * de conta desativada só aparece depois de a palavra-passe estar certa.
     *
     * @throws ValidationException
     */
    public function validarCredenciais(): User
    {
        $this->garantirNaoBloqueado();

        if (! Auth::validate($this->only('email', 'password'))) {
            RateLimiter::hit($this->chaveLimitador());

            throw ValidationException::withMessages([
                'email' => 'As credenciais indicadas não são válidas.',
            ]);
        }

        /** @var User $utilizador */
        $utilizador = Auth::getLastAttempted();

        if (! $utilizador->ativo) {
            RateLimiter::hit($this->chaveLimitador());

            throw ValidationException::withMessages([
                'email' => 'Esta conta está desativada. Contacta o administrador da tua empresa.',
            ]);
        }

        RateLimiter::clear($this->chaveLimitador());

        return $utilizador;
    }

    protected function garantirNaoBloqueado(): void
    {
        if (! RateLimiter::tooManyAttempts($this->chaveLimitador(), 5)) {
            return;
        }

        event(new Lockout($this));

        $segundos = RateLimiter::availableIn($this->chaveLimitador());

        throw ValidationException::withMessages([
            'email' => "Demasiadas tentativas. Tenta novamente dentro de {$segundos} segundos.",
        ]);
    }

    protected function chaveLimitador(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
PHP

escrever Modules/Core/app/Http/Controllers/Auth/AuthenticatedSessionController.php <<'PHP'
<?php

namespace Modules\Core\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Core\Http\Requests\LoginRequest;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('core::auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $utilizador = $request->validarCredenciais();

        if ($utilizador->temDoisFatoresAtivos()) {
            // Ainda NÃO autenticado: guarda só o estado do desafio. A sessão só
            // é aberta no DesafioDoisFatoresController, depois do código.
            $request->session()->regenerate();
            $request->session()->put(DesafioDoisFatoresController::CHAVE_SESSAO, [
                'id' => $utilizador->getKey(),
                'lembrar' => $request->boolean('lembrar'),
                'ate' => now()->addMinutes(DesafioDoisFatoresController::VALIDADE_MINUTOS)->timestamp,
            ]);

            return redirect()->route('core.2fa.desafio');
        }

        Auth::guard('web')->login($utilizador, $request->boolean('lembrar'));

        $request->session()->regenerate();

        return redirect()->intended(route('core.painel'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
PHP

escrever Modules/Core/tests/Feature/LoginDoisFatoresTest.php <<'PHP'
<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Http\Controllers\Auth\DesafioDoisFatoresController;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\DoisFatoresService;
use Tests\TestCase;

/**
 * Liga o /login ao desafio de 2FA. O desafio em si está em DesafioDoisFatoresTest.
 */
class LoginDoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-11-23 12:00:10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function servico(): DoisFatoresService
    {
        return app(DoisFatoresService::class);
    }

    /** @return array{0: User, 1: string} utilizador e segredo */
    protected function comDoisFatores(): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa 560000001', 'nif' => '560000001']);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Dois Fatores',
            'email' => 'dois@teste.ao',
            'password' => 'Senha-Forte-123!',
        ]);

        $segredo = $this->servico()->prepararAtivacao($utilizador);
        $codigos = $this->servico()->confirmarAtivacao($utilizador, $this->codigoNoPasso($segredo));

        $this->assertNotNull($codigos);

        return [$utilizador->fresh(), $segredo];
    }

    protected function codigoNoPasso(string $segredo, int $deslocamento = 0): string
    {
        return $this->servico()->codigo($segredo, $this->servico()->passoDe(now()->timestamp) + $deslocamento);
    }

    protected function credenciais(array $extra = []): array
    {
        return ['email' => 'dois@teste.ao', 'password' => 'Senha-Forte-123!', ...$extra];
    }

    public function test_palavra_passe_errada_nao_cria_estado_de_desafio(): void
    {
        $this->comDoisFatores();

        $this->post('/login', $this->credenciais(['password' => 'errada']))
            ->assertSessionHasErrors('email');

        $this->assertNull(session(DesafioDoisFatoresController::CHAVE_SESSAO));
        $this->assertGuest();
    }

    public function test_conta_desativada_com_2fa_nao_chega_ao_desafio(): void
    {
        [$utilizador] = $this->comDoisFatores();
        $utilizador->forceFill(['ativo' => false])->save();

        $this->post('/login', $this->credenciais())->assertSessionHasErrors('email');

        $this->assertNull(session(DesafioDoisFatoresController::CHAVE_SESSAO));
        $this->assertGuest();
    }

    public function test_o_estado_pendente_guarda_utilizador_validade_e_lembrar(): void
    {
        [$utilizador] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais(['lembrar' => '1']))
            ->assertRedirect(route('core.2fa.desafio'));

        $estado = session(DesafioDoisFatoresController::CHAVE_SESSAO);

        $this->assertEquals($utilizador->id, $estado['id']);
        $this->assertTrue($estado['lembrar']);
        $this->assertSame(
            now()->addMinutes(DesafioDoisFatoresController::VALIDADE_MINUTOS)->timestamp,
            $estado['ate'],
        );
        $this->assertGuest();
    }

    public function test_o_pedido_de_lembrar_sobrevive_ao_desafio(): void
    {
        [$utilizador, $segredo] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais(['lembrar' => '1']));

        $this->post(route('core.2fa.desafio.store'), ['codigo' => $this->codigoNoPasso($segredo, 1)])
            ->assertRedirect(route('core.painel'));

        $this->assertAuthenticatedAs($utilizador);
        $this->assertNotNull($utilizador->fresh()->remember_token);
    }

    public function test_sem_lembrar_nao_fica_cookie_de_sessao_prolongada(): void
    {
        [$utilizador, $segredo] = $this->comDoisFatores();

        $this->post('/login', $this->credenciais());

        $this->post(route('core.2fa.desafio.store'), ['codigo' => $this->codigoNoPasso($segredo, 1)])
            ->assertRedirect(route('core.painel'));

        $this->assertAuthenticatedAs($utilizador);
        $this->assertNull($utilizador->fresh()->remember_token);
    }
}
PHP

# ---------------------------------------------------------------------------
# 2. Sintaxe, formatação e testes (aborta ANTES do commit se algo falhar)
# ---------------------------------------------------------------------------
echo "== 2. Sintaxe, formatação e testes =="

[ "$(git rev-parse --abbrev-ref HEAD)" = "$BRANCH_ALVO" ] || falhar "Branch mudou durante o script."

for f in Modules/Core/app/Http/Requests/LoginRequest.php \
         Modules/Core/app/Http/Controllers/Auth/AuthenticatedSessionController.php \
         Modules/Core/tests/Feature/LoginDoisFatoresTest.php; do
  php -l "$f"
done

vendor/bin/pint --dirty

echo "-- Desafio de 2FA + login --"
php artisan test Modules/Core/tests/Feature/DesafioDoisFatoresTest.php \
                 Modules/Core/tests/Feature/LoginDoisFatoresTest.php \
                 Modules/Core/tests/Feature/LoginContaDesativadaTest.php \
                 Modules/Core/tests/Feature/RegistoSessaoSeguraTest.php

echo "-- Suite do Core --"
php artisan test Modules/Core/tests

# ---------------------------------------------------------------------------
# 3. Commit, suite completa e push (mesma branch)
# ---------------------------------------------------------------------------
echo "== 3. Commit =="

git add Modules/Core
git commit -m "fix(core): ligar o login ao desafio de 2FA (CORE-03 parte C, passo 2b)

O /login usava Auth::attempt(), que abre a sessão logo, pelo que o desafio de
2FA nunca era acionado. Agora o LoginRequest só valida as credenciais e o
AuthenticatedSessionController guarda o estado pendente (id, lembrar, validade)
e redireciona para o desafio quando a conta tem 2FA ativo. Contas sem 2FA
entram como antes."

echo "== Suite completa =="
if ! php artisan test; then
  echo
  echo "⚠ A suite completa falhou. O commit ficou LOCAL em $BRANCH_ALVO; não fiz push."
  echo "  Envia-me as linhas FAIL e corrijo antes de publicar."
  exit 1
fi

git push origin "$BRANCH_ALVO"

echo
echo "✅ Passo 2b enviado para $BRANCH_ALVO ($(git rev-parse --short HEAD))."
