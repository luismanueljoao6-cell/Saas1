#!/usr/bin/env bash
# =============================================================================
# fix-auditoria-geral.sh — correções da auditoria geral do Saas1 (main @ 7cc3e46)
#
# Uso (na raiz do repositório, no terminal do Codespace):
#     bash fix-auditoria-geral.sh                   # aplica, migra, testa e faz commits locais
#     bash fix-auditoria-geral.sh --sem-migrar      # não corre php artisan migrate
#     bash fix-auditoria-geral.sh --sem-testes      # não corre pint --test nem os testes
#     bash fix-auditoria-geral.sh --sem-commit      # deixa as alterações por commitar
#     bash fix-auditoria-geral.sh --sem-npm         # não corre npm audit fix
#     bash fix-auditoria-geral.sh --manter-antigos  # não apaga scripts/patches/views residuais
#
# É idempotente: pode ser corrido mais do que uma vez. NÃO faz push.
# Faz um commit pequeno por tema; se os testes falharem, nada é commitado
# (exceto o commit de estilo do passo 1).
#
# O QUE CORRIGE (por ordem de gravidade)
#  1. CI: `pint --test` falhava em 9 ficheiros (o passo dos testes nem chegava a correr).
#  2. Estúdio/portal do cliente: 5 rotas davam HTTP 500 (reproduzir, descarregar,
#     comentar, aprovar, ajustes). O Laravel passa os parâmetros da rota por POSIÇÃO:
#     com {token} e {versao}, o token caía em `int $versao` (TypeError).
#     + teste que varre as 110 rotas com controller e impede a repetição.
#  3. Core: o botão "Sair" do cabeçalho nunca aparecia (a rota chama-se core.logout).
#  4. Core/API: um Bearer token dava 500 (faltava a tabela personal_access_tokens do Sanctum).
#  5. Segurança web: cabeçalhos HTTP (nosniff, X-Frame-Options, Referrer-Policy,
#     Permissions-Policy, HSTS em produção), política de passwords em produção,
#     Alpine.js com versão fixa + SRI, rotas `storage/{path}` desativadas.
#  6. Dependências/CI: npm audit fix (2 críticas + 2 altas, só dev), dependabot,
#     actions fixadas por SHA, timeouts, smoke do `artisan optimize`.
#  7. Documentação: .env.example completo; webhook ProxyPay descrito como o código
#     realmente funciona (HMAC em X-Signature; PROXYPAY_WEBHOOK_TOKEN não é usado).
#  8. Higiene: ficheiros residuais na raiz, views duplicadas, composer.json.
#
# NÃO ALTERA (decisão tua): as fotos de pedidos do Atelier ficam no disco `public`
# (acessíveis por URL sem login); mudar exige migrar os ficheiros existentes.
# =============================================================================
set -Eeuo pipefail
trap 'printf "\nFalhou na linha %s (%s). Nada foi enviado para o remoto.\n" "$LINENO" "$BASH_COMMAND" >&2' ERR

MIGRAR=1; TESTAR=1; COMMITAR=1; NPM=1; LIMPAR=1
for arg in "$@"; do
  case "$arg" in
    --sem-migrar) MIGRAR=0 ;;
    --sem-testes) TESTAR=0 ;;
    --sem-commit) COMMITAR=0 ;;
    --sem-npm) NPM=0 ;;
    --manter-antigos) LIMPAR=0 ;;
    -h|--ajuda) awk '/^# =+$/ {n++; next} n==1 {sub(/^# ?/, ""); print} n>=2 {exit}' "$0"; exit 0 ;;
    *) echo "Opção desconhecida: $arg (usa --ajuda)"; exit 2 ;;
  esac
done

# --------------------------------------------------------------------------
# Utilitários
# --------------------------------------------------------------------------
info()  { printf '\n==> %s\n' "$*"; }
ok()    { printf '    [ok] %s\n' "$*"; }
ja()    { printf '    [já aplicado] %s\n' "$*"; }
aviso() { printf '    [AVISO] %s\n' "$*"; }
erro()  { printf '\nERRO: %s\n' "$*" >&2; exit 1; }

# substituir <ficheiro> <texto antigo> <texto novo> [marcador]
# Se o marcador (por omissão, o próprio texto novo) já está no ficheiro, não faz
# nada; se o texto antigo não existe (ou aparece mais do que uma vez) falha em
# vez de adivinhar. O marcador existe para o Pint poder reformatar o código
# entre execuções sem que a segunda execução volte a inserir o mesmo trecho.
substituir() {
  local f="$1" r
  [ -f "$f" ] || erro "ficheiro em falta: $f"
  r=$(F="$f" V="$2" N="$3" M="${4:-$3}" perl -e '
    use strict; use warnings;
    open(my $h, "<:raw", $ENV{F}) or die "abrir: $!";
    local $/; my $c = <$h>; close $h;
    my ($v, $n, $m) = ($ENV{V}, $ENV{N}, $ENV{M});
    if (index($c, $m) >= 0) { print "ja"; exit 0 }
    my $p = index($c, $v);
    if ($p < 0) { print "ancora"; exit 0 }
    if (index($c, $v, $p + 1) >= 0) { print "multiplo"; exit 0 }
    substr($c, $p, length($v)) = $n;
    open(my $o, ">:raw", $ENV{F}) or die "escrever: $!";
    print $o $c; close $o; print "ok";
  ')
  case "$r" in
    ok) ok "$f" ;;
    ja) ja "$f" ;;
    ancora) erro "não encontrei o trecho a alterar em $f (o ficheiro mudou desde a auditoria?)" ;;
    multiplo) erro "o trecho a alterar aparece mais do que uma vez em $f" ;;
    *) erro "falha inesperada em $f: $r" ;;
  esac
}

# substituir_todos <ficheiro> <texto antigo> <texto novo>  (todas as ocorrências)
substituir_todos() {
  local f="$1" r
  [ -f "$f" ] || erro "ficheiro em falta: $f"
  r=$(F="$f" V="$2" N="$3" perl -e '
    use strict; use warnings;
    open(my $h, "<:raw", $ENV{F}) or die "abrir: $!";
    local $/; my $c = <$h>; close $h;
    my ($v, $n) = ($ENV{V}, $ENV{N});
    if (index($c, $v) < 0) { print((index($c, $n) >= 0) ? "ja" : "ancora"); exit 0 }
    $c =~ s/\Q$v\E/$n/g;
    open(my $o, ">:raw", $ENV{F}) or die "escrever: $!";
    print $o $c; close $o; print "ok";
  ')
  case "$r" in
    ok) ok "$f" ;;
    ja) ja "$f" ;;
    ancora) erro "não encontrei o trecho a alterar em $f" ;;
    *) erro "falha inesperada em $f: $r" ;;
  esac
}

# adicionar_import <ficheiro.php> <instrução use sem 'use' nem ';'>
# Insere o import a seguir ao último 'use' do ficheiro; se já lá estiver (em
# qualquer posição), não faz nada. O Pint ordena os imports no fim.
adicionar_import() {
  local f="$1" r
  [ -f "$f" ] || erro "ficheiro em falta: $f"
  r=$(F="$f" U="$2" perl -e '
    use strict; use warnings;
    open(my $h, "<:raw", $ENV{F}) or die "abrir: $!";
    local $/; my $c = <$h>; close $h;
    my $u = $ENV{U};
    if ($c =~ /^use \Q$u\E;[ \t]*\r?$/m) { print "ja"; exit 0 }
    my $ultimo = -1;
    while ($c =~ /^use [^;\n]+;[ \t]*\r?\n/mg) { $ultimo = pos($c) }
    if ($ultimo < 0) { print "ancora"; exit 0 }
    substr($c, $ultimo, 0) = "use $u;\n";
    open(my $o, ">:raw", $ENV{F}) or die "escrever: $!";
    print $o $c; close $o; print "ok";
  ')
  case "$r" in
    ok) ok "$f (import $2)" ;;
    ja) ja "$f (import $2)" ;;
    *) erro "não consegui acrescentar o import $2 a $f" ;;
  esac
}

# escrever <caminho>  (conteúdo no stdin). Nunca sobrescreve um ficheiro existente
# (pode ter sido reformatado pelo Pint ou alterado por ti).
escrever() {
  local f="$1" tmp
  tmp="$(mktemp)"
  cat > "$tmp"
  if [ -f "$f" ]; then
    ja "$f (já existe)"
    rm -f "$tmp"
    return 0
  fi
  mkdir -p "$(dirname "$f")"
  mv "$tmp" "$f"
  chmod 644 "$f"
  ok "criado $f"
}

REMOVIDOS=()
COMMIT_MSGS=()
COMMIT_PATHS=()
existe() { [ -e "$1" ] || git ls-files --error-unmatch -- "$1" >/dev/null 2>&1; }

# confirmar "<mensagem>" <caminho>...
# AGENDA um commit por tema (só dos caminhos indicados). Os commits só são
# criados por executar_commits, depois de as verificações finais passarem.
confirmar() {
  [ "$COMMITAR" = 1 ] || return 0
  local msg="$1"
  shift
  COMMIT_MSGS+=("$msg")
  COMMIT_PATHS+=("$(printf '%s\n' "$@")")
}

executar_commits() {
  [ "$COMMITAR" = 1 ] || return 0
  local i p msg lista
  for i in "${!COMMIT_MSGS[@]}"; do
    msg="${COMMIT_MSGS[$i]}"
    lista=()
    while IFS= read -r p; do
      if [ -n "$p" ] && existe "$p"; then lista+=("$p"); fi
    done <<< "${COMMIT_PATHS[$i]}"
    [ "${#lista[@]}" -gt 0 ] || continue
    git add -A -- "${lista[@]}"
    if git diff --cached --quiet; then
      ja "nada a commitar: ${msg%%$'\n'*}"
    else
      git commit -q -m "$msg"
      ok "commit: ${msg%%$'\n'*}"
    fi
  done
  COMMIT_MSGS=()
  COMMIT_PATHS=()
}

# --------------------------------------------------------------------------
# Pré-requisitos
# --------------------------------------------------------------------------
cd "$(git rev-parse --show-toplevel)" || erro "corre este script dentro do repositório Saas1."
[ -d Modules/Core/app ] && [ -d Modules/EstudioMusica/app ] && [ -f bootstrap/app.php ] \
  || erro "não parece o repositório Saas1 (Modules/Core ou Modules/EstudioMusica em falta)."
command -v php >/dev/null  || erro "php não encontrado."
command -v perl >/dev/null || erro "perl não encontrado."
[ -f vendor/autoload.php ]  || erro "vendor/ em falta: corre 'composer install' primeiro."
[ -f .env ] || aviso ".env não existe — os testes precisam de APP_KEY (cp .env.example .env && php artisan key:generate)."
if [ "$COMMITAR" = 1 ] && [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  erro "há alterações por commitar. Faz commit/stash primeiro (ou usa --sem-commit)."
fi

# ==========================================================================
info "1/10 Estilo — o passo 'pint --test' do CI falhava"
# ==========================================================================
if [ -x vendor/bin/pint ]; then
  if vendor/bin/pint --test >/dev/null 2>&1; then
    ja "o código já cumpre o Pint"
  else
    vendor/bin/pint --quiet || true
    mapfile -t ALTERADOS < <(git diff --name-only)
    ok "Pint reformatou ${#ALTERADOS[@]} ficheiro(s)"
    confirmar "style: aplica o Laravel Pint

O passo 'vendor/bin/pint --test' do CI falhava (ordem dos imports, espaços em
operadores unários) e, por ser anterior ao 'php artisan test', impedia os
testes de correr. Alterações só de formatação." "${ALTERADOS[@]}"
    executar_commits
  fi
else
  aviso "vendor/bin/pint não encontrado — salto este passo."
fi

# ==========================================================================
info "2/10 Estúdio — rotas do portal do cliente davam HTTP 500"
# ==========================================================================
CTRL=Modules/EstudioMusica/app/Http/Controllers/PortalClienteController.php
substituir "$CTRL" 'public function reproduzir(Request $request, int $versao)' \
                   'public function reproduzir(Request $request, string $token, int $versao)'
substituir "$CTRL" 'public function descarregar(Request $request, int $versao)' \
                   'public function descarregar(Request $request, string $token, int $versao)'
substituir "$CTRL" 'public function comentar(Request $request, int $versao)' \
                   'public function comentar(Request $request, string $token, int $versao)'
substituir "$CTRL" 'public function aprovar(Request $request, int $versao,' \
                   'public function aprovar(Request $request, string $token, int $versao,'
substituir "$CTRL" 'public function solicitarAjustes(Request $request, int $versao,' \
                   'public function solicitarAjustes(Request $request, string $token, int $versao,'

escrever tests/Feature/AssinaturasDeRotasTest.php <<'__FIM_PHP__'
<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * O Laravel passa os parâmetros de rota aos métodos dos controllers POR
 * POSIÇÃO. Se a rota é `portal/{token}/versoes/{versao}` e o método só
 * declara `int $versao`, o token cai em `$versao` e o pedido rebenta com
 * TypeError (HTTP 500). Este teste percorre todas as rotas com controller de
 * todos os módulos e garante que a assinatura acompanha os parâmetros da rota.
 */
class AssinaturasDeRotasTest extends TestCase
{
    public function test_assinaturas_dos_controllers_acompanham_os_parametros_das_rotas(): void
    {
        $incoerentes = [];

        foreach (Route::getRoutes() as $rota) {
            $acao = $rota->getActionName();

            if ($acao === 'Closure' || ! str_contains($acao, '@')) {
                continue;
            }

            [$classe, $metodo] = explode('@', $acao);

            if (! class_exists($classe) || ! method_exists($classe, $metodo)) {
                $incoerentes[] = "{$rota->uri()} -> {$acao} (método inexistente)";

                continue;
            }

            $parametrosDaRota = $rota->parameterNames();
            $proprios = [];

            foreach ((new ReflectionMethod($classe, $metodo))->getParameters() as $parametro) {
                $tipo = $parametro->getType();
                $injetado = false;

                if ($tipo instanceof ReflectionNamedType && ! $tipo->isBuiltin()) {
                    // Serviços e Request vêm do contentor; models (binding
                    // implícito) e parâmetros com o nome de um parâmetro da
                    // rota vêm da própria rota.
                    $injetado = ! is_subclass_of($tipo->getName(), Model::class)
                        && ! in_array($parametro->getName(), $parametrosDaRota, true);
                }

                if (! $injetado) {
                    $proprios[] = $parametro->getName();
                }
            }

            if ($proprios !== array_slice($parametrosDaRota, 0, count($proprios))) {
                $incoerentes[] = sprintf(
                    '%s %s -> %s  (rota: [%s] | método: [%s])',
                    implode('|', $rota->methods()),
                    $rota->uri(),
                    $acao,
                    implode(', ', $parametrosDaRota),
                    implode(', ', $proprios),
                );
            }
        }

        $this->assertSame(
            [],
            $incoerentes,
            "Assinaturas incoerentes com os parâmetros da rota:\n".implode("\n", $incoerentes),
        );
    }
}
__FIM_PHP__

escrever Modules/EstudioMusica/tests/Feature/PortalRotasTest.php <<'__FIM_PHP__'
<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * As rotas do portal têm DOIS parâmetros ({token} e {versao}) e o Laravel
 * passa-os aos métodos por posição: sem `$token` na assinatura, o token caía
 * em `int $versao` e o pedido rebentava com TypeError (HTTP 500). Cada teste
 * exercita uma rota do portal que recebe {versao}.
 */
class PortalRotasTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-de-teste-rotas-0123456789abcdef0123456789ab';

    protected Empresa $empresa;

    protected VersaoAudio $versao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Rotas', 'nif' => '400000016']);
        app(TenantManager::class)->set($this->empresa->id);

        $cliente = (new Cliente)->forceFill([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Artista Rotas',
            'token_portal' => hash('sha256', self::TOKEN),
            'token_portal_expira_em' => now()->addDay(),
        ]);
        $cliente->save();

        $projeto = ProjetoMusical::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $cliente->id,
            'nome' => 'Projeto Rotas',
            'tipo_cobranca' => 'hora',
        ]);
        $faixa = FaixaMusical::create([
            'empresa_id' => $this->empresa->id,
            'projeto_musical_id' => $projeto->id,
            'nome' => 'Faixa 1',
            'ordem' => 1,
        ]);
        $this->versao = VersaoAudio::create([
            'empresa_id' => $this->empresa->id,
            'faixa_musical_id' => $faixa->id,
            'rotulo' => 'Mix v1',
            'caminho_ficheiro' => 'estudiomusica/versoes-audio/inexistente.wav',
            'estado' => 'pendente_aprovacao',
            'enviada_em' => now(),
        ]);
    }

    protected function rota(string $nome): string
    {
        return route("estudiomusica.portal.{$nome}", ['token' => self::TOKEN, 'versao' => $this->versao->id]);
    }

    public function test_comentar_regista_o_marcador_do_cliente(): void
    {
        $this->post($this->rota('versoes.comentar'), [
            'tempo_segundos' => 83,
            'comentario' => 'Mais graves no refrão',
        ])->assertRedirect()->assertSessionHas('sucesso');

        $this->assertSame(1, $this->versao->marcadores()->count());
    }

    public function test_pedir_ajustes_muda_o_estado_da_versao(): void
    {
        $this->post($this->rota('versoes.ajustes'))
            ->assertRedirect()
            ->assertSessionHas('sucesso');

        $this->assertSame('ajustes_solicitados', $this->versao->fresh()->estado);
    }

    public function test_descarregar_antes_da_quitacao_e_recusado_com_mensagem(): void
    {
        $this->get($this->rota('versoes.descarregar'))
            ->assertRedirect()
            ->assertSessionHas('erro');
    }

    public function test_reproduzir_sem_pre_visualizacao_responde_503_e_nao_500(): void
    {
        // Sem o ficheiro original não há pré-visualização: o controller aborta
        // de propósito com 503. Antes da correção dava 500 (TypeError).
        $this->get($this->rota('versoes.reproduzir'))->assertStatus(503);
    }
}
__FIM_PHP__

confirmar "fix(estudio): portal do cliente deixa de dar 500 em reproduzir/descarregar/comentar/aprovar/ajustes

As rotas têm {token} e {versao}, mas os métodos só declaravam \$versao. O
Laravel passa os parâmetros de rota por posição, por isso o token caía em
'int \$versao' (TypeError, HTTP 500). Corrige as 5 assinaturas e acrescenta:
- AssinaturasDeRotasTest: varre todas as rotas com controller dos módulos;
- PortalRotasTest: exercita as rotas do portal que recebem {versao}.
Corrige também as 3 falhas de PortalTermosTest." \
  "$CTRL" tests/Feature/AssinaturasDeRotasTest.php Modules/EstudioMusica/tests/Feature/PortalRotasTest.php

# ==========================================================================
info "3/10 Core — o botão 'Sair' nunca aparecia"
# ==========================================================================
HDR=Modules/Core/resources/views/partials/header.blade.php
substituir "$HDR" "Route::has('logout')" "Route::has('core.logout')"
substituir "$HDR" "route('logout')" "route('core.logout')"

escrever Modules/Core/tests/Feature/CabecalhoSairTest.php <<'__FIM_PHP__'
<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * O cabeçalho usava a rota 'logout', que não existe (chama-se 'core.logout'):
 * Route::has() dava falso e o botão "Sair" nunca era desenhado.
 */
class CabecalhoSairTest extends TestCase
{
    use RefreshDatabase;

    public function test_painel_mostra_o_formulario_de_sair(): void
    {
        $this->withoutVite();

        $empresa = Empresa::create(['nome_comercial' => 'Empresa Sair', 'nif' => '440000001']);
        $empresa->aderirServicos(['faturacao']);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Sair',
            'email' => 'sair@teste.test',
            'password' => bcrypt('segredo123'),
        ]);

        $this->actingAs($utilizador)
            ->get(route('core.painel'))
            ->assertOk()
            ->assertSee('action="'.route('core.logout').'"', false);
    }
}
__FIM_PHP__

confirmar "fix(core): botão de sair do cabeçalho usa a rota core.logout

O cabeçalho testava Route::has('logout'), mas a rota chama-se 'core.logout':
o botão nunca era desenhado e o utilizador não conseguia terminar sessão pelo
painel. Acrescenta teste de regressão." \
  "$HDR" Modules/Core/tests/Feature/CabecalhoSairTest.php

# ==========================================================================
info "4/10 Core/API — Bearer token dava 500 (faltava personal_access_tokens)"
# ==========================================================================
escrever database/migrations/2026_10_26_000001_create_personal_access_tokens_table.php <<'__FIM_PHP__'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela de tokens do Laravel Sanctum (o User usa HasApiTokens e a rota
 * /api/core/empresa usa auth:sanctum). Sem ela, qualquer pedido com
 * "Authorization: Bearer ..." rebentava com erro de SQL em vez de dar 401.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
__FIM_PHP__

API=Modules/Core/routes/api.php
substituir "$API" '| Requer laravel/sanctum instalado e configurado (não incluído neste
| módulo). Serve principalmente para uma futura app móvel ou SPA separada
| consultar a empresa do utilizador autenticado.' '| Autenticação por tokens do Laravel Sanctum (tabela personal_access_tokens,
| criada em database/migrations). Serve principalmente para uma futura app
| móvel ou SPA separada consultar a empresa do utilizador autenticado.'

escrever Modules/Core/tests/Feature/ApiSanctumTest.php <<'__FIM_PHP__'
<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Tests\TestCase;

class ApiSanctumTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_invalido_devolve_401_e_nao_rebenta_com_500(): void
    {
        $this->withHeader('Authorization', 'Bearer 1|token-invalido')
            ->getJson('/api/core/empresa')
            ->assertUnauthorized();
    }

    public function test_token_valido_devolve_a_empresa_do_utilizador(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa API', 'nif' => '430000001']);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador API',
            'email' => 'api@teste.test',
            'password' => bcrypt('segredo123'),
        ]);

        $token = $utilizador->createToken('teste')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/core/empresa')
            ->assertOk()
            ->assertJsonPath('nif', '430000001');
    }
}
__FIM_PHP__

confirmar "fix(core): tabela personal_access_tokens para o Sanctum

O User usa HasApiTokens e /api/core/empresa usa auth:sanctum, mas a tabela de
tokens nunca foi criada: um pedido com Bearer token dava erro de SQL (500) em
vez de 401. Acrescenta a migração (idempotente) e testes; corrige o comentário
desatualizado da rota." \
  database/migrations/2026_10_26_000001_create_personal_access_tokens_table.php "$API" \
  Modules/Core/tests/Feature/ApiSanctumTest.php

# ==========================================================================
info "5/10 Segurança web — cabeçalhos HTTP e política de passwords"
# ==========================================================================
escrever Modules/Core/app/Http/Middleware/CabecalhosDeSeguranca.php <<'__FIM_PHP__'
<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança básicos em todas as respostas (web, API, 401, 404...).
 * Registado como middleware GLOBAL (ver CoreServiceProvider).
 *
 * Nunca substitui um cabeçalho que a própria rota já tenha definido (o portal
 * do cliente, por exemplo, usa Referrer-Policy: no-referrer). Não define CSP
 * de propósito: as views usam scripts em linha e o Alpine.js vem de uma CDN.
 */
class CabecalhosDeSeguranca
{
    /** @var array<string, string> */
    protected const CABECALHOS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        foreach (self::CABECALHOS as $nome => $valor) {
            if (! $resposta->headers->has($nome)) {
                $resposta->headers->set($nome, $valor);
            }
        }

        // HSTS só em produção e só quando o pedido chegou por HTTPS (atrás de
        // um proxy, requer TRUSTED_PROXIES). Sem includeSubDomains de propósito.
        if ($request->isSecure() && app()->isProduction() && ! $resposta->headers->has('Strict-Transport-Security')) {
            $resposta->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $resposta;
    }
}
__FIM_PHP__

PROV=Modules/Core/app/Providers/CoreServiceProvider.php
adicionar_import "$PROV" 'Illuminate\Contracts\Http\Kernel as HttpKernel'
adicionar_import "$PROV" 'Illuminate\Validation\Rules\Password'
adicionar_import "$PROV" 'Modules\Core\Http\Middleware\CabecalhosDeSeguranca'
substituir "$PROV" '$this->limparTenantEntreJobs();' \
'$this->limparTenantEntreJobs();
        $this->configurarPoliticaDePasswords();
        $this->registarCabecalhosDeSeguranca();' \
'$this->configurarPoliticaDePasswords();'
substituir "$PROV" '    protected function registerViews(): void' \
'    /**
     * Em produção exige 10+ caracteres com maiúsculas, minúsculas e números;
     * fora de produção mantém o mínimo de 8 para não atrapalhar o desenvolvimento.
     */
    protected function configurarPoliticaDePasswords(): void
    {
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()
            : Password::min(8));
    }

    /**
     * Cabeçalhos de segurança em TODAS as respostas. É middleware global e não
     * de grupo porque o middleware auth tem prioridade na ordenação e responde
     * (401/redirect) antes de um middleware de grupo chegar a correr.
     */
    protected function registarCabecalhosDeSeguranca(): void
    {
        $this->app->make(HttpKernel::class)->pushMiddleware(CabecalhosDeSeguranca::class);
    }

    protected function registerViews(): void' \
'function configurarPoliticaDePasswords(): void'

escrever Modules/Core/tests/Feature/CabecalhosDeSegurancaTest.php <<'__FIM_PHP__'
<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Core\Http\Middleware\CabecalhosDeSeguranca;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CabecalhosDeSegurancaTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginas_web_levam_os_cabecalhos_basicos(): void
    {
        $this->withoutVite();

        $this->get(route('core.login'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_respostas_de_erro_tambem_levam_os_cabecalhos(): void
    {
        // 401 da API: o middleware auth responde antes dos middlewares de grupo.
        $this->getJson('/api/core/empresa')
            ->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $this->get('/rota-que-nao-existe')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_nao_substitui_cabecalhos_definidos_pela_rota(): void
    {
        $resposta = (new CabecalhosDeSeguranca)->handle(
            Request::create('/portal/x'),
            fn () => new Response('ok', 200, ['Referrer-Policy' => 'no-referrer']),
        );

        $this->assertSame('no-referrer', $resposta->headers->get('Referrer-Policy'));
        $this->assertSame('SAMEORIGIN', $resposta->headers->get('X-Frame-Options'));
    }

    public function test_hsts_so_em_producao_e_so_sobre_https(): void
    {
        $middleware = new CabecalhosDeSeguranca;
        $seguir = fn () => new Response('ok');
        $https = Request::create('https://exemplo.test/painel');

        $this->assertFalse($middleware->handle($https, $seguir)->headers->has('Strict-Transport-Security'));

        $this->app['env'] = 'production';

        $this->assertSame('max-age=31536000', $middleware->handle($https, $seguir)->headers->get('Strict-Transport-Security'));
        $this->assertFalse($middleware->handle(Request::create('http://exemplo.test/painel'), $seguir)->headers->has('Strict-Transport-Security'));
    }
}
__FIM_PHP__

escrever Modules/Core/tests/Feature/PoliticaPasswordsTest.php <<'__FIM_PHP__'
<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class PoliticaPasswordsTest extends TestCase
{
    protected function valida(string $password): bool
    {
        return Validator::make(['password' => $password], ['password' => [Password::defaults()]])->passes();
    }

    public function test_fora_de_producao_basta_o_minimo_de_8_caracteres(): void
    {
        $this->assertTrue($this->valida('abcdefgh'));
        $this->assertFalse($this->valida('abcdefg'));
    }

    public function test_em_producao_exige_10_caracteres_com_maiusculas_minusculas_e_numeros(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse($this->valida('abcdefgh'));
        $this->assertFalse($this->valida('abcdefghij'));
        $this->assertFalse($this->valida('ABCDEFGHIJ1'));
        $this->assertFalse($this->valida('Abcdefghij'));
        $this->assertTrue($this->valida('Abcdefghi10'));
    }
}
__FIM_PHP__

confirmar "feat(core): cabeçalhos de segurança HTTP e política de passwords reforçada em produção

- CabecalhosDeSeguranca (middleware global, também nas respostas 401/404/419):
  nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy e HSTS (só em
  produção/HTTPS). Não substitui cabeçalhos já definidos pela rota (o portal
  usa no-referrer).
- Password::defaults(): em produção 10+ caracteres com maiúsculas, minúsculas
  e números; fora de produção mantém o mínimo de 8." \
  Modules/Core/app/Http/Middleware/CabecalhosDeSeguranca.php "$PROV" \
  Modules/Core/tests/Feature/CabecalhosDeSegurancaTest.php Modules/Core/tests/Feature/PoliticaPasswordsTest.php

# ==========================================================================
info "6/10 Alpine.js com versão fixa + SRI; rotas storage/{path} desativadas"
# ==========================================================================
# Versões/hashes tirados do registo npm (dist/cdn.min.js). Antes de gravar o
# SRI, o script confirma que a CDN serve exatamente esse ficheiro; se não
# conseguir confirmar, fixa só a versão (sem integrity) e avisa.
ALPINE_V_CORE="3.17.4"
ALPINE_SRI_CORE="sha384-5/joNqFnRyVWzXp99bHot6RHG+EksGp+USSgZwPar7T9SD9PKKER37n/8bXBAZGd"
ALPINE_V_ESTUDIO="3.14.9"
ALPINE_SRI_ESTUDIO="sha384-9Ax3MmS9AClxJyd5/zafcXXjxmwFhZCdsT6HJoJjarvCaAkJlk5QDzjLJm+Wdx5F"

sri_cdn() {
  curl -fsSL --max-time 20 "https://cdn.jsdelivr.net/npm/alpinejs@$1/dist/cdn.min.js" 2>/dev/null \
    | openssl dgst -sha384 -binary 2>/dev/null | openssl base64 -A 2>/dev/null | sed 's/^/sha384-/'
}

# aplicar_alpine <ficheiro> <versão> <sri esperado>
aplicar_alpine() {
  local f="$1" v="$2" sri="$3" real linha antes depois
  [ -f "$f" ] || erro "ficheiro em falta: $f"
  real="$(sri_cdn "$v" 2>/dev/null || true)"
  if [ -z "$real" ] && grep -q 'alpinejs@[^"]*"[^>]*integrity=' "$f"; then
    ja "$f (sem rede para reconfirmar o SRI; mantido)"
    return 0
  fi
  if [ "$real" = "$sri" ]; then
    linha='<script src="https://cdn.jsdelivr.net/npm/alpinejs@'"$v"'/dist/cdn.min.js" integrity="'"$sri"'" crossorigin="anonymous" defer></script>'
  else
    aviso "SRI de alpinejs@$v não confirmado pela CDN — fixo só a versão (volta a correr o script com rede para acrescentar o SRI)"
    linha='<script src="https://cdn.jsdelivr.net/npm/alpinejs@'"$v"'/dist/cdn.min.js" defer></script>'
  fi
  antes="$(sha256sum "$f" | cut -d' ' -f1)"
  LINHA="$linha" perl -0777 -pi -e 's{<script src="https://cdn\.jsdelivr\.net/npm/alpinejs\@[^"]+/dist/cdn\.min\.js"(?: integrity="[^"]*" crossorigin="anonymous")? defer></script>}{$ENV{LINHA}}g' "$f"
  depois="$(sha256sum "$f" | cut -d' ' -f1)"
  if [ "$antes" != "$depois" ]; then ok "$f (alpinejs@$v)"; else ja "$f"; fi
}

LAY_CORE=Modules/Core/resources/views/layouts/app.blade.php
LAY_ATELIER=Modules/Atelier/resources/views/landing/mostrar.blade.php
LAY_ESTUDIO=Modules/EstudioMusica/resources/views/layouts/publico.blade.php
aplicar_alpine "$LAY_CORE" "$ALPINE_V_CORE" "$ALPINE_SRI_CORE"
aplicar_alpine "$LAY_ATELIER" "$ALPINE_V_CORE" "$ALPINE_SRI_CORE"
aplicar_alpine "$LAY_ESTUDIO" "$ALPINE_V_ESTUDIO" "$ALPINE_SRI_ESTUDIO"

# O disco 'local' (privado) servia ficheiros em GET/PUT storage/{path}; nada no
# código usa URLs temporárias, por isso as duas rotas são superfície a mais.
substituir config/filesystems.php "'root' => storage_path('app/private'),
            'serve' => true," "'root' => storage_path('app/private'),
            'serve' => false,"

confirmar "fix(seguranca): Alpine.js com versão fixa e SRI; desativa as rotas storage/{path}

- Core e Atelier carregavam alpinejs@3.x.x (sem versão fixa, sem SRI) de uma
  CDN de terceiros; passam a 3.17.4 com integrity. O Estúdio (3.14.9) ganha SRI.
- Disco local com 'serve' => false: remove as rotas GET/PUT storage/{path}
  (nada usa URLs temporárias; os ficheiros privados saem por controllers)." \
  "$LAY_CORE" "$LAY_ATELIER" "$LAY_ESTUDIO" config/filesystems.php

# ==========================================================================
info "7/10 Dependências npm (npm audit fix)"
# ==========================================================================
if [ "$NPM" = 1 ] && [ -f package-lock.json ] && command -v npm >/dev/null; then
  antes="$(sha256sum package-lock.json | cut -d' ' -f1)"
  npm audit fix --package-lock-only --no-fund >/dev/null 2>&1 || aviso "npm audit fix devolveu erro (sem rede?)"
  depois="$(sha256sum package-lock.json | cut -d' ' -f1)"
  if [ "$antes" != "$depois" ]; then ok "package-lock.json atualizado (package.json inalterado)"; else ja "package-lock.json sem alterações"; fi
  if npm audit --audit-level=moderate >/dev/null 2>&1; then
    ok "npm audit: sem vulnerabilidades moderadas ou superiores"
  else
    aviso "npm audit ainda reporta vulnerabilidades — corre 'npm audit' para ver o detalhe"
  fi
else
  aviso "passo npm saltado (--sem-npm, sem package-lock.json ou npm indisponível)"
fi
confirmar "chore(deps): npm audit fix (2 vulnerabilidades críticas e 2 altas em dependências de desenvolvimento)

Só o package-lock.json muda (concurrently/shell-quote, vite, source-map-js).
Depois do 'git pull' corre 'npm ci' para sincronizar o node_modules." package-lock.json

# ==========================================================================
info "8/10 CI — dependabot, timeouts, actions fixadas por SHA, smoke do optimize"
# ==========================================================================
escrever .github/dependabot.yml <<'__FIM_YML__'
version: 2
updates:
  - package-ecosystem: composer
    directory: /
    schedule:
      interval: weekly
    open-pull-requests-limit: 5

  - package-ecosystem: npm
    directory: /
    schedule:
      interval: weekly
    open-pull-requests-limit: 5

  # Mantém as actions fixadas por SHA atualizadas.
  - package-ecosystem: github-actions
    directory: /
    schedule:
      interval: weekly
__FIM_YML__

CI=.github/workflows/ci.yml
substituir_todos "$CI" 'uses: actions/checkout@v4' \
  'uses: actions/checkout@34e114876b0b11c390a56381ad16ebd13914f8d5 # v4.3.1'
substituir_todos "$CI" 'uses: shivammathur/setup-php@v2' \
  'uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # v2'
substituir_todos "$CI" 'uses: actions/cache@v4' \
  'uses: actions/cache@0057852bfaa89a56745cba8c7296529d2fc39830 # v4.3.0'
substituir "$CI" '  qualidade:
    runs-on: ubuntu-latest
' '  qualidade:
    runs-on: ubuntu-latest
    timeout-minutes: 20
'
substituir "$CI" '  testes-mysql:
    runs-on: ubuntu-latest
' '  testes-mysql:
    runs-on: ubuntu-latest
    timeout-minutes: 30
'
substituir "$CI" '      - run: php artisan test
      - run: composer audit' '      - run: php artisan test
      # Garante que config, rotas, vistas e eventos se conseguem pôr em cache (deploy).
      - run: php artisan optimize
      - run: composer audit'

confirmar "chore(ci): dependabot, timeouts, actions fixadas por SHA e smoke do artisan optimize

- Actions fixadas por commit SHA (comentário com a versão); o dependabot
  (github-actions, composer, npm) mantém-nas atualizadas.
- timeout-minutes nos dois jobs.
- 'php artisan optimize' no CI: falha se config/rotas/vistas/eventos não fizerem cache." \
  .github/dependabot.yml "$CI"

# ==========================================================================
info "9/10 Documentação — .env.example e webhook ProxyPay"
# ==========================================================================
substituir .env.example '# Segredo do webhook: configura na ProxyPay o URL
#   https://SEU-DOMINIO/api/webhooks/proxypay?token=<este valor>
PROXYPAY_WEBHOOK_TOKEN=' '# Webhook: configura na ProxyPay o URL https://SEU-DOMINIO/api/webhooks/proxypay
# (sem token na query string: o pedido é validado pela assinatura X-Signature,
# HMAC-SHA-256 do corpo com a PROXYPAY_API_KEY).'
substituir README.md '`PROXYPAY_ENTITY_ID`, `PROXYPAY_WEBHOOK_TOKEN` |' '`PROXYPAY_ENTITY_ID` |'
substituir README.md 'URL a configurar no painel: `https://SEU-DOMINIO/api/webhooks/proxypay?token=<PROXYPAY_WEBHOOK_TOKEN>`.' \
  'URL a configurar no painel: `https://SEU-DOMINIO/api/webhooks/proxypay` (sem token na query string: o pedido é autenticado pela assinatura `X-Signature`, HMAC-SHA-256 do corpo com a `PROXYPAY_API_KEY`).'

if grep -q 'Chaves opcionais dos módulos' .env.example; then
  ja ".env.example (bloco de chaves opcionais)"
else
  [ -z "$(tail -c1 .env.example)" ] || echo >> .env.example
  cat >> .env.example <<'__FIM_ENV__'

# --- Chaves opcionais dos módulos (todas com valor por omissão no código) ---
# Descomenta só o que precisares de alterar. A descrição de cada chave está em
# Modules/<Módulo>/config/config.php.

# Core
# CORE_TRIAL_DIAS=14
# CORE_PERIODO_TOLERANCIA_DIAS=5
# CORE_SUPER_ADMIN_EMAILS=
# CORE_AUTH_GUARD=web

# Subscrições
# SUBSCRICOES_MOEDA=AOA
# SUBSCRICOES_VALIDADE_REFERENCIA_DIAS=3
# SUBSCRICOES_AVISO_RENOVACAO_DIAS=3
# SUBSCRICOES_TRIAL_MAX_UTILIZADORES=3
# SUBSCRICOES_TRIAL_MAX_FATURAS_MES=50

# Faturação / AGT
# FATURACAO_ND_LIQUIDA_IVA=false
# FATURACAO_AGT_URL_HOMOLOGACAO=https://sifphml.minfin.gov.ao/sigt/fe/v1
# FATURACAO_AGT_URL_PRODUCAO=https://sifp.minfin.gov.ao/sigt/fe/v1
# FATURACAO_AGT_TIMEOUT=20
# FATURACAO_AGT_MAX_TENTATIVAS=5
# FATURACAO_AGT_CONSULTA_INTERVALO=60
# FATURACAO_AGT_SEM_RESPOSTA_HORAS=24
# FATURACAO_AGT_OPERATION_TYPE=SG
# FATURACAO_AGT_SIGNATURE_VERSION=

# Atelier
# ATELIER_CANAIS_NOTIFICACAO=mail
# ATELIER_WHATSAPP_BASE_URL=
# ATELIER_WHATSAPP_TOKEN=
# ATELIER_WHATSAPP_NUMERO_ID=
# ATELIER_SMS_BASE_URL=
# ATELIER_SMS_API_KEY=
# ATELIER_SMS_REMETENTE=ATELIER
# ATELIER_LEMBRETE_PROVA_HORAS=24
# ATELIER_MESES_INATIVIDADE=6
# ATELIER_PORTAL_LINK_DIAS=7

# Estúdio de Música
# ESTUDIOMUSICA_PERCENTUAL_SINAL_PADRAO=40
# ESTUDIOMUSICA_DESCONTO_ANIVERSARIO=15
# ESTUDIOMUSICA_VALIDADE_CUPOM_DIAS=30
# ESTUDIOMUSICA_PORTAL_TOKEN_VALIDADE_DIAS=30
# WHATSAPP_CLOUD_API_BASE_URL=https://graph.facebook.com/v21.0
# WHATSAPP_CLOUD_API_PHONE_NUMBER_ID=
# WHATSAPP_CLOUD_API_TOKEN=
# SMS_TWILIO_ACCOUNT_SID=
# SMS_TWILIO_AUTH_TOKEN=
# SMS_TWILIO_NUMERO_REMETENTE=

# --- Sessão e cookies (produção atrás de HTTPS) ---
# SESSION_SECURE_COOKIE=true
# SESSION_SAME_SITE=lax
# SESSION_HTTP_ONLY=true
# Rotação da APP_KEY: chaves antigas separadas por vírgula.
# APP_PREVIOUS_KEYS=
__FIM_ENV__
  ok ".env.example (bloco de chaves opcionais acrescentado)"
fi

confirmar "docs: .env.example completo e webhook ProxyPay descrito como o código funciona

- PROXYPAY_WEBHOOK_TOKEN não é lido por nenhum código: o webhook é validado por
  HMAC-SHA-256 do corpo (cabeçalho X-Signature) com a PROXYPAY_API_KEY, como já
  dizia o README do módulo Subscricoes. README e .env.example corrigidos.
- .env.example passa a listar as chaves lidas pelos configs dos módulos e as de
  cookies de sessão (SESSION_SECURE_COOKIE, ...), todas comentadas." \
  .env.example README.md

# ==========================================================================
info "10/10 Higiene do repositório e composer.json"
# ==========================================================================
# 10a) Ficheiro vazio cujo nome é uma mensagem de erro colada por engano.
while IFS= read -r -d '' f; do
  case "$f" in
    "As credenciais indicadas"*)
      if [ ! -s "$f" ]; then rm -f -- "$f"; REMOVIDOS+=("$f"); ok "removido ficheiro vazio '$f'"; fi ;;
  esac
done < <(git ls-files -z)

if [ "$LIMPAR" = 1 ]; then
  # 10b) t.sh é cópia byte a byte de fase2-faturacao.sh.
  if [ -f t.sh ] && [ -f fase2-faturacao.sh ] && cmp -s t.sh fase2-faturacao.sh; then
    rm -f t.sh; REMOVIDOS+=(t.sh); ok "removido t.sh (duplicado de fase2-faturacao.sh)"
  fi
  # 10c) Scripts e patches de rondas anteriores, já aplicados (continuam no histórico do git).
  for f in s.sh fase2-faturacao.sh fix-subscricoes.sh correcoes-config.patch correcoes-estudio.patch correcoes-estudio-ronda4.patch; do
    if [ -f "$f" ] && git ls-files --error-unmatch -- "$f" >/dev/null 2>&1; then
      rm -f -- "$f"; REMOVIDOS+=("$f"); ok "removido $f (já aplicado/obsoleto; fica no histórico do git)"
    fi
  done
  # 10d) Views por defeito do Laravel sem qualquer referência.
  if [ -f resources/views/painel.blade.php ] && cmp -s resources/views/painel.blade.php Modules/Core/resources/views/painel.blade.php; then
    rm -f resources/views/painel.blade.php; REMOVIDOS+=(resources/views/painel.blade.php)
    ok "removida resources/views/painel.blade.php (cópia idêntica de core::painel)"
  fi
  if [ -f resources/views/welcome.blade.php ] \
     && ! git grep -qE "view\(['\"]welcome['\"]|Route::view\([^)]*['\"]welcome['\"]" -- '*.php' '*.blade.php'; then
    rm -f resources/views/welcome.blade.php; REMOVIDOS+=(resources/views/welcome.blade.php)
    ok "removida resources/views/welcome.blade.php (não é usada por nenhuma rota)"
  fi
  # O Laravel (view:cache / php artisan optimize) exige que resources/views exista
  # e o git não guarda diretórios vazios: sem isto, um clone limpo (CI, deploy) falha.
  if [ -d resources/views ] && [ ! -f resources/views/.gitkeep ] \
     && [ -z "$(find resources/views -type f 2>/dev/null | head -n 1)" ]; then
    : > resources/views/.gitkeep
    ok "criado resources/views/.gitkeep (o diretório ficou sem views e o 'php artisan optimize' exige que exista)"
  fi
else
  aviso "--manter-antigos: scripts, patches e views residuais mantidos"
fi

# 10e) composer.json: descrição, autoload-dev dos testes dos módulos e formatação.
# (O plugin wikimedia/composer-merge-plugin em allow-plugins É necessário: vem do
#  nwidart/laravel-modules. Não lhe tocamos.)
CJ_PHP="$(mktemp)"
cat > "$CJ_PHP" <<'__FIM_PHP__'
<?php
$ficheiro = 'composer.json';
$original = file_get_contents($ficheiro);
$j = json_decode($original);

if (! is_object($j)) {
    fwrite(STDERR, "composer.json inválido\n");
    exit(1);
}

if (str_contains((string) ($j->description ?? ''), 'skeleton application')) {
    $j->description = 'Plataforma SaaS multi-tenant de faturação e gestão para o mercado angolano (Laravel + módulos).';
}

$j->{'autoload-dev'} ??= new stdClass;
$j->{'autoload-dev'}->{'psr-4'} ??= new stdClass;
foreach (glob('Modules/*/tests', GLOB_ONLYDIR) ?: [] as $dir) {
    $modulo = basename(dirname($dir));
    $ns = "Modules\\{$modulo}\\Tests\\";
    if (! isset($j->{'autoload-dev'}->{'psr-4'}->{$ns})) {
        $j->{'autoload-dev'}->{'psr-4'}->{$ns} = "Modules/{$modulo}/tests/";
    }
}

$novo = json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
if ($novo !== $original) {
    file_put_contents($ficheiro, $novo);
    echo 'alterado';
} else {
    echo 'igual';
}
__FIM_PHP__
R="$(php "$CJ_PHP")"
rm -f "$CJ_PHP"
if [ "$R" = "alterado" ]; then
  ok "composer.json normalizado (autoload-dev dos módulos, descrição, formatação)"
  if command -v composer >/dev/null; then
    composer validate --no-check-publish >/dev/null || erro "composer validate falhou depois de alterar o composer.json"
    composer dump-autoload -q
    ok "composer validate e dump-autoload"
  else
    aviso "composer não encontrado — corre 'composer validate' e 'composer dump-autoload' à mão"
  fi
else
  ja "composer.json"
fi

confirmar "chore: remove ficheiros residuais e normaliza o composer.json

- Remove o ficheiro vazio com nome de mensagem de erro, t.sh (cópia de
  fase2-faturacao.sh), scripts/patches já aplicados e views por defeito sem uso
  (tudo continua no histórico do git). resources/views/.gitkeep mantém o
  diretório: o 'php artisan optimize' falha se resources/views não existir.
- composer.json: autoload-dev para os testes dos módulos (68 classes sem mapa
  PSR-4), descrição do projeto e indentação do bloco autoload." \
  composer.json resources/views/.gitkeep ${REMOVIDOS[@]+"${REMOVIDOS[@]}"}

# ==========================================================================
info "Verificações finais"
# ==========================================================================
if [ "$MIGRAR" = 1 ]; then
  php artisan migrate --force --no-interaction >/dev/null \
    || erro "php artisan migrate falhou (base de dados indisponível?). Corrige a ligação ou usa --sem-migrar."
  ok "php artisan migrate"
fi

# Não usamos 'optimize:clear': inclui cache:clear, que precisa da base de dados
# e apagaria a cache da aplicação. Limpamos só os ficheiros gerados.
php artisan optimize >/dev/null
php artisan config:clear >/dev/null && php artisan route:clear >/dev/null \
  && php artisan view:clear >/dev/null && php artisan event:clear >/dev/null
ok "php artisan optimize (config, rotas, vistas e eventos fazem cache)"

if [ "$TESTAR" = 1 ]; then
  if [ -x vendor/bin/pint ]; then
    if ! vendor/bin/pint --test >/dev/null 2>&1; then
      vendor/bin/pint --quiet || true
      vendor/bin/pint --test >/dev/null 2>&1 || erro "o Pint continua a falhar — corre 'vendor/bin/pint --test' para ver o detalhe."
      aviso "o Pint reformatou ficheiros novos; ficam incluídos nos commits"
    fi
    ok "pint --test"
  fi
  echo
  if ! php artisan test; then
    erro "os testes falharam. Os commits dos passos 2 a 10 NÃO foram criados; as alterações ficam na árvore para inspeção."
  fi
fi

# --------------------------------------------------------------------------
# Commits (só chegamos aqui se tudo passou)
# --------------------------------------------------------------------------
if [ "$COMMITAR" = 1 ]; then
  info "Commits locais"
  executar_commits
  mapfile -t RESTO < <(git diff --name-only)
  if [ "${#RESTO[@]}" -gt 0 ]; then
    confirmar "style: formatação final do Pint nos ficheiros da auditoria" "${RESTO[@]}"
    executar_commits
  fi
fi

echo
if [ "$COMMITAR" = 1 ]; then
  echo "Concluído. Commits locais criados (git log --oneline -12). Nada foi enviado."
else
  echo "Concluído. Alterações deixadas na árvore (--sem-commit). Nada foi enviado."
fi
echo
echo "Próximos passos:"
echo "  1. git push"
echo "  2. npm ci && npm run build      (sincroniza o node_modules com o package-lock.json)"
echo "  3. rm fix-auditoria-geral.sh    (este script não está versionado)"
echo "  4. Em produção, define: APP_DEBUG=false, SESSION_SECURE_COOKIE=true e TRUSTED_PROXIES."
