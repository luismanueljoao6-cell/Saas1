#!/usr/bin/env bash
# =============================================================================
# fix-subscricoes-auditoria.sh — correções da auditoria (26/10/2026) ao
# módulo Subscricoes do Saas1.
#
# Uso (na raiz do repositório, no terminal do Codespace):
#     bash fix-subscricoes-auditoria.sh --simular     # só mostra o que faria
#     bash fix-subscricoes-auditoria.sh               # aplica, testa, migra e faz commit local
#     bash fix-subscricoes-auditoria.sh --sem-migrar  # não corre php artisan migrate
#     bash fix-subscricoes-auditoria.sh --sem-testes  # não corre testes (nem faz commit)
#     bash fix-subscricoes-auditoria.sh --sem-commit  # deixa as alterações por commitar
#
# Garantias:
#  - Idempotente: pode ser corrido mais do que uma vez.
#  - Cada ficheiro alterado é copiado antes para storage/app/backups-auditoria/.
#  - Se a sintaxe PHP falhar, ou testes que passavam deixarem de passar,
#    reverte tudo sozinho.
#  - Uma âncora que não encontra (ficheiro diferente do esperado) faz esse
#    ficheiro ser IGNORADO por inteiro e listado — nunca o corrompe.
#  - Não faz push.
# =============================================================================
set -euo pipefail

SIMULAR=0; MIGRAR=1; TESTAR=1; COMMITAR=1
for arg in "$@"; do
  case "$arg" in
    --simular)    SIMULAR=1 ;;
    --sem-migrar) MIGRAR=0 ;;
    --sem-testes) TESTAR=0 ;;
    --sem-commit) COMMITAR=0 ;;
    -h|--ajuda)   sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "Opção desconhecida: $arg (usa --ajuda)"; exit 2 ;;
  esac
done

cd "$(git rev-parse --show-toplevel)"
M="Modules/Subscricoes"
[ -d "$M/app" ] || { echo "ERRO: $M não encontrado. Corre este script dentro do repositório Saas1."; exit 1; }
PY="$(command -v python3 || command -v python || true)"
[ -n "$PY" ] || { echo "ERRO: python3 não encontrado."; exit 1; }
if [ "$SIMULAR" = 0 ]; then
  command -v php >/dev/null || { echo "ERRO: php não encontrado."; exit 1; }
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
# storage/app é ignorado pelo git: os backups nunca entram num commit por engano.
BK="storage/app/backups-auditoria/$STAMP"
if [ "$SIMULAR" = 0 ]; then
  mkdir -p "$BK/files"
  : > "$BK/alterados.txt"
  : > "$BK/novos.txt"
  : > "$BK/ignorados.txt"
fi
export SIMULAR BK M

reverter() {
  echo "==> A REVERTER todas as alterações deste script..."
  if [ -s "$BK/alterados.txt" ]; then
    while IFS= read -r f; do
      if [ -n "$f" ]; then cp -p "$BK/files/$f" "$f"; echo "    restaurado: $f"; fi
    done < "$BK/alterados.txt"
  fi
  if [ -s "$BK/novos.txt" ]; then
    while IFS= read -r f; do
      if [ -n "$f" ]; then rm -f "$f"; echo "    removido:   $f"; fi
    done < "$BK/novos.txt"
  fi
  echo "    Backup e logs mantidos em $BK"
}

correr_testes() { # $1 = rótulo; resto = argumentos do "php artisan test"
  local rotulo="$1"; shift
  local log="$BK/testes-${rotulo}.log"
  export XDEBUG_MODE=off
  if php artisan test "$@" >"$log" 2>&1; then
    echo "    ${rotulo}: OK"
    return 0
  fi
  echo "    ${rotulo}: FALHOU (log completo em $log). Últimas linhas:"
  tail -n 25 "$log" | sed 's/^/      | /'
  return 1
}

# -----------------------------------------------------------------------------
# 1) Testes de referência (antes de tocar em nada)
# -----------------------------------------------------------------------------
BASE_OK=1
if [ "$SIMULAR" = 0 ] && [ "$TESTAR" = 1 ]; then
  echo "==> Testes ANTES das correções (referência)"
  if ! correr_testes antes --filter=Subscricoes; then
    BASE_OK=0
    echo "    Aviso: já havia testes a falhar antes; não os uso para decidir reversões."
  fi
fi

# -----------------------------------------------------------------------------
# 2) Correções (patches com âncoras + ficheiros novos)
# -----------------------------------------------------------------------------
if [ "$SIMULAR" = 1 ]; then
  echo "==> SIMULAÇÃO: nada é escrito"
else
  echo "==> A aplicar correções"
fi

set +e
"$PY" - <<'PY'
import os
import re
import shutil
import sys

SIMULAR = os.environ.get("SIMULAR") == "1"
BK = os.environ.get("BK", "")
M = os.environ.get("M", "Modules/Subscricoes")

resumo = {"alterados": 0, "novos": 0, "ja": 0}
ignorados = []


def dizer(simbolo, caminho, msg):
    print(f"  {simbolo} {caminho}: {msg}")


def ignorar(caminho, motivo):
    dizer("!", caminho, motivo)
    ignorados.append(caminho)
    if not SIMULAR:
        with open(os.path.join(BK, "ignorados.txt"), "a", encoding="utf-8") as f:
            f.write(f"{caminho}: {motivo}\n")


def ler(caminho):
    with open(caminho, "rb") as f:
        bruto = f.read().decode("utf-8")
    return bruto.replace("\r\n", "\n"), "\r\n" in bruto


def gravar(caminho, texto, crlf=False):
    if crlf:
        texto = texto.replace("\n", "\r\n")
    with open(caminho, "wb") as f:
        f.write(texto.encode("utf-8"))


_copiados = set()


def fazer_backup(caminho):
    if SIMULAR or caminho in _copiados:
        return
    destino = os.path.join(BK, "files", caminho)
    os.makedirs(os.path.dirname(destino), exist_ok=True)
    shutil.copy2(caminho, destino)
    with open(os.path.join(BK, "alterados.txt"), "a", encoding="utf-8") as f:
        f.write(caminho + "\n")
    _copiados.add(caminho)


def regex_da_ancora(ancora):
    # Espaços/quebras de linha/indentação do ficheiro real não têm de coincidir.
    return re.compile(r"\s+".join(re.escape(p) for p in ancora.split()))


def aplicar(texto, ancora, novo, vezes):
    achados = list(regex_da_ancora(ancora).finditer(texto))
    if len(achados) != vezes:
        return None, len(achados)
    for m in reversed(achados):
        ini = m.start()
        j = ini
        while j > 0 and texto[j - 1] in " \t":
            j -= 1
        if j == 0 or texto[j - 1] == "\n":
            ini = j  # âncora no início da linha: 'novo' traz a sua própria indentação
        texto = texto[:ini] + novo + texto[m.end():]
    return texto, len(achados)


def patch_ficheiro(caminho, patches):
    """patches: lista de (ancora, novo, marcador[, vezes]). Tudo ou nada por ficheiro."""
    if not os.path.exists(caminho):
        ignorar(caminho, "ficheiro não existe")
        return
    texto, crlf = ler(caminho)
    aplicados = 0
    ja = 0
    for p in patches:
        ancora, novo, marcador = p[0], p[1], p[2]
        vezes = p[3] if len(p) > 3 else 1
        assert marcador in novo, f"marcador fora do texto novo: {marcador!r}"
        if marcador in texto:
            ja += 1
            continue
        resultado, n = aplicar(texto, ancora, novo, vezes)
        if resultado is None:
            curta = " ".join(ancora.split())[:70]
            ignorar(caminho, f"âncora encontrada {n}x (esperado {vezes}): {curta!r} — ficheiro ignorado por inteiro")
            return
        texto = resultado
        aplicados += 1
    if aplicados == 0:
        dizer("=", caminho, "já aplicado")
        resumo["ja"] += 1
        return
    if SIMULAR:
        dizer("+", caminho, f"aplicável ({aplicados} alteração(ões); {ja} já feita(s))")
        resumo["alterados"] += 1
        return
    fazer_backup(caminho)
    gravar(caminho, texto, crlf)
    dizer("+", caminho, f"{aplicados} alteração(ões) aplicada(s)")
    resumo["alterados"] += 1


def novo_ficheiro(caminho, conteudo):
    conteudo = conteudo.strip("\n") + "\n"
    if os.path.exists(caminho):
        atual, _ = ler(caminho)
        if atual.strip() == conteudo.strip():
            dizer("=", caminho, "já existe (igual)")
            resumo["ja"] += 1
        else:
            ignorar(caminho, "já existe e é diferente — mantido, não sobrescrevo")
        return
    if SIMULAR:
        dizer("+", caminho, "seria criado")
        resumo["novos"] += 1
        return
    os.makedirs(os.path.dirname(caminho), exist_ok=True)
    with open(os.path.join(BK, "novos.txt"), "a", encoding="utf-8") as f:
        f.write(caminho + "\n")
    gravar(caminho, conteudo)
    dizer("+", caminho, "criado")
    resumo["novos"] += 1


# =============================================================================
# FICHEIROS NOVOS
# =============================================================================

novo_ficheiro(f"{M}/app/Exceptions/PedidoEmPreparacaoException.php", r'''<?php

namespace Modules\Subscricoes\Exceptions;

use RuntimeException;

/**
 * Já há um pedido de pagamento a ser criado para esta empresa/subscrição
 * (o gateway ainda está a responder). A mensagem é escrita para o utilizador.
 * Estende RuntimeException para não quebrar quem já apanha RuntimeException.
 */
class PedidoEmPreparacaoException extends RuntimeException {}
''')

novo_ficheiro(f"{M}/database/migrations/2026_10_26_000001_add_indices_de_rotina_a_subscricoes_e_pagamentos.php", r'''<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A rotina diária e a reconciliação filtram por estado + data, sem
        // empresa_id: o índice (empresa_id, estado) existente não as serve.
        Schema::table('subscricoes', function (Blueprint $table) {
            $table->index(['estado', 'termina_em'], 'subscricoes_estado_termina_em_index');
        });

        Schema::table('pagamentos', function (Blueprint $table) {
            $table->index(['estado', 'expira_em'], 'pagamentos_estado_expira_em_index');
            $table->index(['subscricao_id', 'estado'], 'pagamentos_subscricao_id_estado_index');
        });
    }

    public function down(): void
    {
        Schema::table('subscricoes', function (Blueprint $table) {
            $table->dropIndex('subscricoes_estado_termina_em_index');
        });

        Schema::table('pagamentos', function (Blueprint $table) {
            $table->dropIndex('pagamentos_estado_expira_em_index');
            $table->dropIndex('pagamentos_subscricao_id_estado_index');
        });
    }
};
''')

novo_ficheiro(f"{M}/tests/Feature/AuditoriaSubscricoesTest.php", r'''<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Subscricoes\Exceptions\PedidoEmPreparacaoException;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;
use Modules\Subscricoes\Models\WebhookEvento;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\PagamentoService;
use Modules\Subscricoes\Services\SubscricaoService;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regressões da auditoria de 26/10/2026 ao módulo Subscrições.
 * Correr só estes: php artisan test --group=auditoria
 */
#[Group('auditoria')]
class AuditoriaSubscricoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->app->singleton(GatewayPagamentoInterface::class, fn () => new class implements GatewayPagamentoInterface
        {
            public function identificador(): string
            {
                return 'gateway-teste';
            }

            public function gerarReferencia(Pagamento $pagamento): array
            {
                return ['referencia_externa' => 'REF-'.$pagamento->id, 'payload' => ['ok' => true]];
            }

            public function validarPedidoWebhook(Request $request): bool
            {
                return true;
            }

            public function interpretarNotificacao(Request $request): array
            {
                return ['referencia_externa' => 'x', 'estado' => 'confirmado', 'payload' => []];
            }
        });
    }

    protected function criarEmpresa(string $nif): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif])->fresh();
    }

    protected function criarUtilizador(Empresa $empresa, string $papel = 'Administrador'): User
    {
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Teste',
            'email' => uniqid('u').'@teste.ao',
            'password' => 'Senha-Forte-123!',
            'ativo' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
        $utilizador->assignRole(Role::firstOrCreate([
            'name' => $papel,
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
        ]));

        return $utilizador;
    }

    protected function plano(string $slug): Plano
    {
        return Plano::create(['nome' => 'Plano '.$slug, 'slug' => $slug, 'preco' => 9900, 'periodo_dias' => 30]);
    }

    protected function cobrancaDe(Empresa $empresa, Plano $plano): Pagamento
    {
        return app(SubscricaoService::class)->iniciar($empresa, $plano);
    }

    public function test_cancelar_renovacao_de_subscricao_pendente_e_recusado_e_preserva_o_pagamento(): void
    {
        $empresa = $this->criarEmpresa('840000001');
        $admin = $this->criarUtilizador($empresa);
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000001'));
        $subscricao = Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();

        $this->actingAs($admin)
            ->from(route('subscricoes.planos'))
            ->post(route('subscricoes.renovacao', ['subscricao' => $subscricao->id]))
            ->assertRedirect(route('subscricoes.planos'))
            ->assertSessionHas('erro');

        $this->assertSame('pendente', Pagamento::withoutGlobalScopes()->findOrFail($pagamento->id)->estado);
        $this->assertTrue((bool) Subscricao::withoutGlobalScopes()->findOrFail($subscricao->id)->renovacao_automatica);
    }

    public function test_retomar_renovacao_de_subscricao_vencida_mostra_erro_em_vez_de_falhar(): void
    {
        $empresa = $this->criarEmpresa('840000002');
        $admin = $this->criarUtilizador($empresa);
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000002'));
        app(PagamentoService::class)->confirmar($pagamento, [], null, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->firstOrFail();
        $subscricao->update([
            'renovacao_automatica' => false,
            'cancelada_em' => now(),
            'termina_em' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->from(route('subscricoes.planos'))
            ->post(route('subscricoes.renovacao', ['subscricao' => $subscricao->id]))
            ->assertRedirect(route('subscricoes.planos'))
            ->assertSessionHas('erro');

        $this->assertFalse((bool) Subscricao::withoutGlobalScopes()->findOrFail($subscricao->id)->renovacao_automatica);
    }

    public function test_iniciar_para_outro_plano_com_pagamento_pendente_mostra_a_mensagem_de_negocio(): void
    {
        $empresa = $this->criarEmpresa('840000003');
        $admin = $this->criarUtilizador($empresa);
        $this->cobrancaDe($empresa, $this->plano('plano-840000003a'));
        $outro = $this->plano('plano-840000003b');

        $this->actingAs($admin)
            ->from(route('subscricoes.planos'))
            ->post(route('subscricoes.iniciar'), ['plano_id' => $outro->id])
            ->assertRedirect(route('subscricoes.planos'))
            ->assertSessionHas('erro', fn ($mensagem) => str_contains((string) $mensagem, 'outro plano'));
    }

    public function test_pedido_recente_sem_referencia_continua_a_bloquear_com_excecao_especifica(): void
    {
        $empresa = $this->criarEmpresa('840000004');
        $plano = $this->plano('plano-840000004');
        $pagamento = $this->cobrancaDe($empresa, $plano);
        Pagamento::withoutGlobalScopes()->whereKey($pagamento->id)->update(['referencia_externa' => null]);

        $this->expectException(PedidoEmPreparacaoException::class);

        app(SubscricaoService::class)->iniciar($empresa, $plano);
    }

    public function test_pedido_abandonado_sem_referencia_e_descartado_e_a_empresa_pode_tentar_de_novo(): void
    {
        $empresa = $this->criarEmpresa('840000005');
        $plano = $this->plano('plano-840000005');
        $abandonado = $this->cobrancaDe($empresa, $plano);
        Pagamento::withoutGlobalScopes()->whereKey($abandonado->id)->update([
            'referencia_externa' => null,
            'created_at' => now()->subMinutes(10),
        ]);

        $novo = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->assertNotSame($abandonado->id, $novo->id);
        $this->assertNotEmpty($novo->referencia_externa);
        $this->assertSame('expirado', Pagamento::withoutGlobalScopes()->findOrFail($abandonado->id)->estado);
        $this->assertSame('cancelada', Subscricao::withoutGlobalScopes()->findOrFail($abandonado->subscricao_id)->estado);
    }

    public function test_servico_desconhecido_no_plano_nao_impede_a_confirmacao_do_pagamento(): void
    {
        $empresa = $this->criarEmpresa('840000006');
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000006'));

        $subscricao = Subscricao::withoutGlobalScopes()->findOrFail($pagamento->subscricao_id);
        $subscricao->servicos = ['servico-que-nao-existe'];
        $subscricao->save();

        $confirmado = app(PagamentoService::class)->confirmar($pagamento->fresh(), [], null, '9900.00');

        $this->assertSame('confirmado', $confirmado->estado);
        $this->assertSame('ativa', $empresa->fresh()->estado_subscricao);
    }

    public function test_confirmacao_manual_de_subscricao_cancelada_limpa_cancelada_em(): void
    {
        $empresa = $this->criarEmpresa('840000007');
        $operador = $this->criarUtilizador($empresa);
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000007'));

        // Estado deixado pela rotina diária: pagamento expirado e subscrição cancelada.
        Pagamento::withoutGlobalScopes()->whereKey($pagamento->id)->update(['estado' => 'expirado']);
        Subscricao::withoutGlobalScopes()->whereKey($pagamento->subscricao_id)->update([
            'estado' => 'cancelada',
            'cancelada_em' => now(),
        ]);

        app(PagamentoService::class)->confirmar($pagamento->fresh(), [], $operador, '9900.00');

        $subscricao = Subscricao::withoutGlobalScopes()->findOrFail($pagamento->subscricao_id);
        $this->assertSame('ativa', $subscricao->estado);
        $this->assertNull($subscricao->cancelada_em);
    }

    public function test_reconciliacao_conta_uma_so_tentativa_por_reprocessamento(): void
    {
        $empresa = $this->criarEmpresa('840000008');
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000008'));

        $evento = WebhookEvento::query()->create([
            'gateway' => 'gateway-teste',
            'chave_evento' => hash('sha256', 'auditoria-840000008'),
            'referencia_externa' => $pagamento->referencia_externa,
            'valor' => '9900.00',
            'corpo_bruto' => '{"reference_id":"'.$pagamento->referencia_externa.'","amount":"9900.00"}',
            'estado' => WebhookEvento::RECEBIDO,
        ]);
        WebhookEvento::query()->whereKey($evento->id)->update(['updated_at' => now()->subHour()]);

        Artisan::call('subscricoes:reconciliar');

        $evento = $evento->fresh();
        $this->assertSame(WebhookEvento::PROCESSADO, $evento->estado);
        $this->assertSame(1, (int) $evento->tentativas);
    }

    public function test_reconciliacao_descarta_pagamento_sem_referencia_abandonado(): void
    {
        $empresa = $this->criarEmpresa('840000009');
        $pagamento = $this->cobrancaDe($empresa, $this->plano('plano-840000009'));
        Pagamento::withoutGlobalScopes()->whereKey($pagamento->id)->update([
            'referencia_externa' => null,
            'created_at' => now()->subHour(),
        ]);

        $codigo = Artisan::call('subscricoes:reconciliar');

        $this->assertSame(0, $codigo);
        $this->assertSame('expirado', Pagamento::withoutGlobalScopes()->findOrFail($pagamento->id)->estado);
        $this->assertSame('cancelada', Subscricao::withoutGlobalScopes()->findOrFail($pagamento->subscricao_id)->estado);
    }

    public function test_comando_de_preco_rejeita_valores_que_o_gateway_nao_aceita(): void
    {
        $plano = $this->plano('plano-840000010');

        foreach (['0.001', '1e3', '12.345', '100000000', '0'] as $preco) {
            $this->artisan('planos:preco', ['slug' => $plano->slug, 'preco' => $preco])->assertExitCode(1);
        }

        $this->assertEquals(9900, (float) Plano::findOrFail($plano->id)->preco);
    }
}
''')

novo_ficheiro(f"{M}/AUDITORIA-2026-10-26.md", r'''# Auditoria do módulo Subscricoes — 26/10/2026

Âmbito: `Modules/Subscricoes` (modelos, serviços, jobs, comandos, rotas, views,
migrations e testes) e a integração com o Core (`Empresa`, `TenantService`,
`VerificarSubscricaoAtiva`).

Contrato ProxyPay v2 conferido com a documentação oficial: assinatura
`X-Signature` (HMAC-SHA-256 do corpo bruto com a API key), `amount` como texto,
`end_datetime` ISO 8601, `custom_fields` só com texto e `DELETE /references/{id}`.
Sem divergências.

## Corrigido (fix-subscricoes-auditoria.sh)

1. Renovação: `alternarRenovacao` só atua em subscrições ativas e a
   `DomainException` deixa de dar erro 500. `cancelarRenovacao` recusa estados
   não ativos (antes, numa subscrição pendente, expirava o primeiro pagamento e
   cancelava a referência no gateway).
2. `iniciar`: as mensagens de negócio (plano indisponível, pagamento pendente
   para outro plano, pedido em preparação) chegam ao utilizador em vez do erro
   genérico.
3. Pedidos de pagamento sem referência, abandonados quando o processo morre a
   meio, bloqueavam a empresa até 3 dias. Passam a ser descartados ao fim de
   2 min em `iniciar`/`gerarRenovacao` (`SUBSCRICOES_PREPARACAO_ABANDONADA_MINUTOS`)
   e ao fim de 15 min pela reconciliação.
4. A reconciliação contava 2 tentativas por reprocessamento (comando + job).
5. `PagamentoService::confirmar`: uma lista de serviços sem nenhum serviço
   conhecido já não impede a confirmação de um pagamento recebido; uma
   subscrição reativada deixa de ficar com `cancelada_em`; docblock do valor.
6. Job diário: falhar ao preparar o serviço de renovações já não salta o
   heartbeat lido por `subscricoes:saude`.
7. Provider: o agendamento só é registado em consola, não em cada pedido HTTP.
8. Views: aviso de erro duplicado em `/planos`; botão "Subscrever" escondido a
   quem não é administrador; preço com decimais; valores copiados com `@js`;
   a página de pagamento pendente atualiza-se sozinha a cada 30 s.
9. `planos:preco` rejeita preços que a ProxyPay não aceita (0,01 a 99 999 999,99;
   no máximo 2 decimais; sem notação científica).
10. Índices para a rotina diária e a reconciliação.

## Não alterado (decisão tua)

- `pagamentos` e `subscricoes` têm `cascadeOnDelete` para a empresa: um
  `forceDelete` apaga registos financeiros (a `Empresa` usa SoftDeletes, por isso
  só afeta a eliminação definitiva).
- `pagamentos.referencia_externa` é única entre gateways. Ao juntar um segundo
  gateway, passar a única por (`gateway`, `referencia_externa`).
- `cancelarReferencia` não faz parte da interface do gateway (usa-se
  `method_exists`): um gateway novo sem esse método deixa de cancelar
  referências sem avisar.
- Ao pagar o primeiro plano, os dias que restavam do trial perdem-se (só se
  somam dias de uma subscrição 'ativa').
- `TenantService::suspender` recomeça a tolerância na data da suspensão (rotina
  das 02:00) e não na data de fim: a tolerância real pode ser até ~1 dia maior
  do que `core.periodo_tolerancia_dias`.
- O layout do Core carrega o Alpine por CDN sem versão fixa nem SRI.
- Um pagamento superior ao devido é rejeitado (falta política de crédito ou
  reembolso).
- `LimitesPlanoService` consulta a base de dados em cada chamada.
''')

# =============================================================================
# PATCHES EM FICHEIROS EXISTENTES
# =============================================================================

# --- Controller: renovação só em subscrição ativa; mensagens de negócio ------
patch_ficheiro(f"{M}/app/Http/Controllers/SubscricaoController.php", [
    (r'''use Illuminate\Http\RedirectResponse;''',
     r'''use DomainException;
use Illuminate\Http\RedirectResponse;''',
     r'''use DomainException;'''),

    (r'''use Modules\Subscricoes\Exceptions\GatewayPagamentoException;''',
     r'''use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Exceptions\PedidoEmPreparacaoException;''',
     r'''use Modules\Subscricoes\Exceptions\PedidoEmPreparacaoException;'''),

    (r'''} catch (Throwable $e) {
            Log::error('Falha inesperada ao iniciar subscrição', ['erro' => $e->getMessage()]);''',
     r'''        } catch (DomainException|PedidoEmPreparacaoException $e) {
            // Mensagens de negócio escritas para o utilizador: plano indisponível,
            // pagamento pendente para outro plano, pedido de pagamento em preparação.
            return back()->with('erro', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Falha inesperada ao iniciar subscrição', ['erro' => $e->getMessage()]);''',
     r'''catch (DomainException|PedidoEmPreparacaoException $e)'''),

    (r'''if ($modelo->renovacao_automatica) {
            $this->subscricaoService->cancelarRenovacao($modelo);

            return back()->with('sucesso', 'Renovação cancelada. Manténs o acesso até ao fim do período pago.');
        }

        $this->subscricaoService->retomarRenovacao($modelo);

        return back()->with('sucesso', 'Renovação retomada.');''',
     r'''        // Só uma subscrição ativa tem renovação para gerir. Numa subscrição
        // pendente, "cancelar a renovação" invalidaria o primeiro pagamento.
        if ($modelo->estado !== 'ativa') {
            return back()->with('erro', 'Só é possível gerir a renovação de uma subscrição ativa.');
        }

        try {
            if ($modelo->renovacao_automatica) {
                $this->subscricaoService->cancelarRenovacao($modelo);

                return back()->with('sucesso', 'Renovação cancelada. Manténs o acesso até ao fim do período pago.');
            }

            $this->subscricaoService->retomarRenovacao($modelo);
        } catch (DomainException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Renovação retomada.');''',
     r'''Só é possível gerir a renovação de uma subscrição ativa.'''),
])

# --- SubscricaoService: pedidos abandonados, guarda do cancelamento ---------
patch_ficheiro(f"{M}/app/Services/SubscricaoService.php", [
    (r'''use Modules\Subscricoes\Exceptions\GatewayPagamentoException;''',
     r'''use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Exceptions\PedidoEmPreparacaoException;''',
     r'''use Modules\Subscricoes\Exceptions\PedidoEmPreparacaoException;'''),

    (r'''new RuntimeException(''',
     r'''new PedidoEmPreparacaoException(''',
     r'''new PedidoEmPreparacaoException(''',
     2),

    (r'''if ($existente) { if ((int) $existente->subscricao->plano_id !== (int) $plano->id) {''',
     r'''                    // Pedido que nunca chegou a ter referência e já passou do tempo razoável
                    // de uma chamada ao gateway: foi abandonado (processo morto a meio).
                    // Descarta-o em vez de bloquear a empresa até ele expirar (dias).
                    if ($existente && $this->descartarPreparacaoAbandonada($existente)) {
                        $existente->subscricao->update(['estado' => 'cancelada', 'cancelada_em' => now()]);
                        $existente = null;
                    }

                    if ($existente) {
                        if ((int) $existente->subscricao->plano_id !== (int) $plano->id) {''',
     r'''$existente->subscricao->update(['estado' => 'cancelada', 'cancelada_em' => now()]);'''),

    (r'''if ($existente) { return [$subscricaoBloqueada, $existente, true]; }''',
     r'''                // Referência de renovação abandonada a meio (sem referência externa).
                if ($existente && $this->descartarPreparacaoAbandonada($existente)) {
                    $existente = null;
                }

                if ($existente) {
                    return [$subscricaoBloqueada, $existente, true];
                }''',
     r'''// Referência de renovação abandonada a meio (sem referência externa).'''),

    (r'''$bloqueada->update([ 'renovacao_automatica' => false, 'cancelada_em' => now(), ]);''',
     r'''            if ($bloqueada->estado !== 'ativa') {
                throw new DomainException(
                    'Só é possível cancelar a renovação de uma subscrição ativa.'
                );
            }

            $bloqueada->update([
                'renovacao_automatica' => false,
                'cancelada_em' => now(),
            ]);''',
     r'''Só é possível cancelar a renovação de uma subscrição ativa.'''),

    (r'''protected function precoAplicavel(Subscricao $subscricao, Plano $plano): mixed''',
     r'''    /**
     * Um pagamento 'pendente' sem referência externa só é legítimo enquanto o
     * pedido que o criou está a falar com o gateway (segundos). Passado esse
     * prazo o processo morreu a meio: a referência nunca foi mostrada a ninguém
     * e deixá-lo vivo bloquearia novas tentativas até expirar (dias).
     * Chamado sob lock da empresa/subscrição.
     */
    protected function descartarPreparacaoAbandonada(Pagamento $pagamento): bool
    {
        if ($pagamento->referencia_externa || $pagamento->created_at === null) {
            return false;
        }

        $minutos = max(1, (int) Config::get('subscricoes.preparacao_abandonada_minutos', 2));

        if ($pagamento->created_at->gt(now()->subMinutes($minutos))) {
            return false;
        }

        $pagamento->update(['estado' => 'expirado']);

        Log::warning('Pedido de pagamento sem referência abandonado — descartado', [
            'pagamento_id' => $pagamento->id,
            'subscricao_id' => $pagamento->subscricao_id,
        ]);

        return true;
    }

    protected function precoAplicavel(Subscricao $subscricao, Plano $plano): mixed''',
     r'''function descartarPreparacaoAbandonada(Pagamento $pagamento): bool'''),
])

# --- PagamentoService: serviços, cancelada_em, docblock ----------------------
patch_ficheiro(f"{M}/app/Services/PagamentoService.php", [
    (r'''use Modules\Core\Services\TenantService;''',
     r'''use Modules\Core\Services\TenantService;
use Modules\Core\Support\Servico;''',
     r'''use Modules\Core\Support\Servico;'''),

    (r'''if ($subscricao->servicos !== null) {
                        if (! empty($subscricao->servicos)) {
                            $empresa->definirServicos($subscricao->servicos);
                        }
                    } elseif (! empty($subscricao->plano->servicos)) {
                        // Compatibilidade com subscrições antigas sem snapshot.
                        $empresa->definirServicos($subscricao->plano->servicos);
                    }''',
     r'''                    // Snapshot da compra; subscrições antigas, sem snapshot, usam o plano.
                    $servicosContratados = $subscricao->servicos ?? $subscricao->plano->servicos;

                    // Só aplica se a lista tiver pelo menos um serviço conhecido:
                    // definirServicos() recusa uma lista final vazia, e isso faria
                    // falhar (e reverter) a confirmação de um pagamento já recebido.
                    if (! empty($servicosContratados) && Servico::comDependencias($servicosContratados) !== []) {
                        $empresa->definirServicos($servicosContratados);
                    }''',
     r'''$servicosContratados = $subscricao->servicos ?? $subscricao->plano->servicos;'''),

    (r'''$subscricao->update([
                        'estado' => 'ativa',
                        'inicio_em' => $subscricao->inicio_em ?? $agora,
                        'termina_em' => $terminaEm,
                    ]);''',
     r'''                    $subscricao->update([
                        'estado' => 'ativa',
                        'inicio_em' => $subscricao->inicio_em ?? $agora,
                        'termina_em' => $terminaEm,
                        // Reativada (ex.: confirmação manual após cancelamento pela rotina):
                        // deixa de constar como cancelada, salvo se a renovação foi cancelada.
                        'cancelada_em' => $subscricao->renovacao_automatica ? null : $subscricao->cancelada_em,
                    ]);''',
     r'''$subscricao->renovacao_automatica ? null : $subscricao->cancelada_em,'''),

    (r'''informado e inferior ao devido, lança DomainException''',
     r'''informado e diferente do devido (a menos ou a mais), lança DomainException''',
     r'''diferente do devido (a menos ou a mais)'''),
])

# --- Reconciliação: contador duplicado e pedidos sem referência -------------
patch_ficheiro(f"{M}/app/Console/ReconciliarPagamentosCommand.php", [
    (r'''$evento->increment('tentativas');

                        ProcessarPagamentoConfirmadoJob::dispatch(''',
     r'''                        // `tentativas` já é incrementado pelo próprio job (atualizarEvento):
                        // contar também aqui duplicava o contador a cada reprocessamento.
                        ProcessarPagamentoConfirmadoJob::dispatch(''',
     r'''contar também aqui duplicava o contador'''),

    (r'''$anomalias = $this->detetarAnomalias();''',
     r'''            $descartados = $this->descartarPagamentosSemReferencia();
            $anomalias = $this->detetarAnomalias();''',
     r'''$descartados = $this->descartarPagamentosSemReferencia();'''),

    (r'''$this->info("Cancelamentos remotos repetidos com sucesso: {$cancelados}");''',
     r'''            $this->info("Cancelamentos remotos repetidos com sucesso: {$cancelados}");
            $this->info("Pagamentos sem referência descartados: {$descartados}");''',
     r'''Pagamentos sem referência descartados: {$descartados}'''),

    (r'''protected function reprocessarEventos(GatewayPagamentoInterface $gateway): int''',
     r'''    /**
     * Um pagamento 'pendente' sem referência há mais de 15 minutos nunca chegou
     * a ser mostrado a ninguém (o pedido que o criou morreu a meio): não há
     * nada a cobrar nem a reconciliar. Expira-o e cancela a subscrição ainda
     * pendente, em vez de o deixar a bloquear a empresa e a gerar alertas.
     */
    protected function descartarPagamentosSemReferencia(): int
    {
        $total = 0;

        Pagamento::query()
            ->where('estado', 'pendente')
            ->whereNull('referencia_externa')
            ->where('created_at', '<', now()->subMinutes(15))
            ->with('subscricao')
            ->chunkById(100, function ($pagamentos) use (&$total) {
                foreach ($pagamentos as $pagamento) {
                    try {
                        $pagamento->update(['estado' => 'expirado']);

                        if ($pagamento->subscricao?->estado === 'pendente') {
                            $pagamento->subscricao->update(['estado' => 'cancelada', 'cancelada_em' => now()]);
                        }

                        $total++;
                    } catch (Throwable $e) {
                        Log::error('Reconciliação: falha ao descartar pagamento sem referência', [
                            'pagamento_id' => $pagamento->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($total > 0) {
            Log::warning('Reconciliação: pagamentos sem referência descartados', ['total' => $total]);
        }

        return $total;
    }

    protected function reprocessarEventos(GatewayPagamentoInterface $gateway): int''',
     r'''function descartarPagamentosSemReferencia(): int'''),
])

# --- Job diário: não saltar o heartbeat se o serviço não se resolver --------
patch_ficheiro(f"{M}/app/Jobs/VerificarSubscricoesExpiradasJob.php", [
    (r'''$subscricaoService = app(SubscricaoService::class);''',
     r'''        try {
            $subscricaoService = app(SubscricaoService::class);
        } catch (Throwable $e) {
            $this->falhas++;
            Log::error('Não foi possível preparar o serviço de renovações (gateway mal configurado?)', [
                'erro' => $e->getMessage(),
            ]);

            return;
        }''',
     r'''Não foi possível preparar o serviço de renovações'''),
])

# --- Provider: agendar só em consola ----------------------------------------
patch_ficheiro(f"{M}/app/Providers/SubscricoesServiceProvider.php", [
    (r'''$this->agendarVerificacaoDiaria();''',
     r'''        // O agendamento só interessa a schedule:run / schedule:work (consola);
        // registá-lo em cada pedido HTTP seria trabalho inútil.
        if ($this->app->runningInConsole()) {
            $this->agendarVerificacaoDiaria();
        }''',
     r'''O agendamento só interessa a schedule:run'''),
])

# --- Config: prazo de pedidos abandonados -----------------------------------
patch_ficheiro(f"{M}/config/config.php", [
    (r'''    'renovacao_preco' => env('SUBSCRICOES_RENOVACAO_PRECO', 'contratado'),''',
     r'''    'renovacao_preco' => env('SUBSCRICOES_RENOVACAO_PRECO', 'contratado'),

    /*
    |--------------------------------------------------------------------------
    | Pedidos de pagamento abandonados (minutos)
    |--------------------------------------------------------------------------
    | Um pagamento 'pendente' sem referência externa só é legítimo durante a
    | chamada ao gateway (segundos). Passado este prazo considera-se que o
    | processo morreu a meio e o pedido é descartado, para a empresa poder
    | tentar de novo em vez de ficar bloqueada até a referência expirar.
    */
    'preparacao_abandonada_minutos' => (int) env('SUBSCRICOES_PREPARACAO_ABANDONADA_MINUTOS', 2),''',
     r'''preparacao_abandonada_minutos'''),
])

# --- View dos planos ---------------------------------------------------------
patch_ficheiro(f"{M}/resources/views/planos/index.blade.php", [
    (r'''@if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                {{ session('erro') }}
            </div>
        @endif''',
     r'''        {{-- Os avisos (sucesso/erro) já são mostrados pelo layout core::layouts.app. --}}''',
     r'''Os avisos (sucesso/erro) já são mostrados pelo layout'''),

    (r'''{{ number_format((float) $plano->preco, 0, ',', ' ') }} {{ $plano->moeda }}''',
     r'''                        {{ number_format((float) $plano->preco, fmod((float) $plano->preco, 1.0) === 0.0 ? 0 : 2, ',', ' ') }} {{ $plano->moeda }}''',
     r'''fmod((float) $plano->preco, 1.0)'''),

    (r'''<form method="POST" action="{{ route('subscricoes.iniciar') }}" class="mt-6">
                        @csrf
                        <input type="hidden" name="plano_id" value="{{ $plano->id }}">
                        <button type="submit"
                                class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                            Subscrever
                        </button>
                    </form>''',
     r'''                    @if (auth()->user()->hasRole('Administrador'))
                        <form method="POST" action="{{ route('subscricoes.iniciar') }}" class="mt-6">
                            @csrf
                            <input type="hidden" name="plano_id" value="{{ $plano->id }}">
                            <button type="submit"
                                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                                Subscrever
                            </button>
                        </form>
                    @else
                        <p class="mt-6 text-xs text-slate-500">
                            Só um administrador da empresa pode contratar ou mudar de plano.
                        </p>
                    @endif''',
     r'''Só um administrador da empresa pode contratar ou mudar de plano.'''),
])

# --- View do pagamento pendente ---------------------------------------------
patch_ficheiro(f"{M}/resources/views/subscricao/pendente.blade.php", [
    (r'''writeText('{{ $entidade }}')''',
     r'''writeText(@js((string) $entidade))''',
     r'''@js((string) $entidade)'''),

    (r'''writeText('{{ $referencia }}')''',
     r'''writeText(@js($referencia))''',
     r'''@js($referencia)'''),

    (r'''@endsection''',
     r'''@endsection

@if ($pagamento->estado === 'pendente')
    @push('scripts')
        <script>
            // Verifica a confirmação sem obrigar a atualizar a página à mão.
            setTimeout(function () { window.location.reload(); }, 30000);
        </script>
    @endpush
@endif''',
     r'''window.location.reload()'''),
])

# --- planos:preco (routes/console.php) --------------------------------------
patch_ficheiro("routes/console.php", [
    (r'''if (! is_numeric($preco) || (float) $preco <= 0) {''',
     r'''    if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $preco) || (float) $preco < 0.01) {''',
     r'''preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $preco)'''),

    (r'''$this->error('O preço tem de ser um número positivo.');''',
     r'''        $this->error('O preço tem de ser positivo, com no máximo 8 inteiros e 2 casas decimais (ex.: 6500 ou 6500.50).');''',
     r'''no máximo 8 inteiros'''),
])

print()
print(f"  Resumo: {resumo['alterados']} ficheiro(s) alterado(s), {resumo['novos']} novo(s), "
      f"{resumo['ja']} já aplicado(s), {len(ignorados)} ignorado(s).")
if ignorados:
    print("  ATENÇÃO: há ficheiros ignorados (linhas com '!'). Os restantes foram tratados;")
    print("  diz-me quais são para eu ajustar as âncoras ao teu código.")
PY
ENG=$?
set -e

if [ "$ENG" -ne 0 ]; then
  echo "ERRO: o motor de patches falhou (código $ENG)."
  [ "$SIMULAR" = 1 ] || reverter
  exit 1
fi

if [ "$SIMULAR" = 1 ]; then
  echo "==> Simulação concluída. Corre sem --simular para aplicar."
  exit 0
fi

TOCADOS=()
while IFS= read -r f; do
  [ -n "$f" ] && TOCADOS+=("$f")
done < <(cat "$BK/alterados.txt" "$BK/novos.txt" | sort -u)

if [ "${#TOCADOS[@]}" -eq 0 ]; then
  echo "==> Nada a fazer: tudo já estava aplicado."
  exit 0
fi

# -----------------------------------------------------------------------------
# 3) Sintaxe PHP
# -----------------------------------------------------------------------------
echo "==> A validar sintaxe PHP (${#TOCADOS[@]} ficheiros tocados)"
for f in "${TOCADOS[@]}"; do
  case "$f" in
    *.php)
      if ! php -l "$f" >"$BK/lint.out" 2>&1; then
        echo "ERRO de sintaxe em $f:"
        sed 's/^/    | /' "$BK/lint.out"
        reverter
        exit 1
      fi
      ;;
  esac
done
echo "    OK"

# -----------------------------------------------------------------------------
# 4) Testes depois das correções
# -----------------------------------------------------------------------------
TUDO_OK=1
if [ "$TESTAR" = 1 ]; then
  echo "==> Testes DEPOIS das correções"
  if ! correr_testes depois --filter=Subscricoes --exclude-group=auditoria; then
    if [ "$BASE_OK" = 1 ]; then
      echo "    Os testes passavam antes e deixaram de passar: a reverter."
      reverter
      exit 1
    fi
    TUDO_OK=0
    echo "    Aviso: continuam a falhar, como antes. Compara os logs em $BK."
  fi
  if ! correr_testes novos --group=auditoria; then
    TUDO_OK=0
    echo "    Os testes novos falharam: as correções ficam aplicadas, mas não faço commit."
  fi
fi

# -----------------------------------------------------------------------------
# 5) Migrações (só depois de os testes passarem)
# -----------------------------------------------------------------------------
if [ "$MIGRAR" = 1 ]; then
  echo "==> A correr migrações"
  if ! php artisan migrate; then
    TUDO_OK=0
    echo "    A migração falhou: vê a mensagem acima. Podes repetir com: php artisan migrate"
  fi
fi

# -----------------------------------------------------------------------------
# 6) Commit local (nunca push)
# -----------------------------------------------------------------------------
if [ "$COMMITAR" = 1 ]; then
  if [ "$TESTAR" = 0 ]; then
    echo "==> Commit ignorado (--sem-testes): revê com 'git diff' e commita à mão."
  elif [ "$TUDO_OK" = 1 ]; then
    echo "==> A fazer commit (local)"
    git add -- "${TOCADOS[@]}"
    git commit -m "fix(subscricoes): auditoria de 26/10/2026 — renovação só em subscrição ativa, pedidos abandonados, reconciliação, serviços desconhecidos, views e índices" \
      || echo "    nada para commitar"
    echo "    Revê com 'git show --stat' e faz 'git push origin main' quando estiveres satisfeito."
  else
    echo "==> Commit ignorado: algo falhou acima."
  fi
fi

if [ -s "$BK/ignorados.txt" ]; then
  echo
  echo "ATENÇÃO — alterações ignoradas (o teu ficheiro difere do esperado):"
  sed 's/^/    /' "$BK/ignorados.txt"
fi

echo
echo "==> Concluído. Backup: $BK"
echo "    Relatório da auditoria: $M/AUDITORIA-2026-10-26.md"
