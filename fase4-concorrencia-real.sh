#!/usr/bin/env bash
# =============================================================================
# Saas1 — PR #33 — FASE 4: concorrência real e idempotência
# Branch: feat/faturacao-fiscal-core
#
# Fecha o ATL-01: o teste "dois_pedidos_simultaneos" corria os pedidos em série.
# Aqui os pedidos correm em PROCESSOS PHP independentes, com ligações MySQL
# próprias, arrancando todos no mesmo instante (barreira por relógio).
#
# Correr na RAIZ do repositório:   bash fase4-concorrencia-real.sh
# Opcional:  FORCE=1 (ignora a verificação da branch)
#            RUN=1   (também corre os testes; exige MySQL DESCARTÁVEL — ver abaixo)
#
# O que cria/altera (não faz commit nem push):
#   tests/Support/ProcessosConcorrentes.php     harness: lança N processos em paralelo
#   tests/Support/concorrencia_worker.php       worker (arranca a app, espera a barreira)
#   Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaRealTest.php
#   Modules/Faturacao/tests/Feature/EmissaoConcorrenciaRealTest.php
#   .github/workflows/ci.yml                    passo novo no job testes-mysql
#
# SEGURANÇA: estes testes comprometem dados (sem transação) e não limpam as
# faturas (triggers de imutabilidade). Por isso só correm se:
#   - SAAS1_CONCORRENCIA_REAL=1, e
#   - o driver for mysql, e
#   - o nome da base de dados contiver "test".
# Fora disso saltam (SKIPPED) e a suite normal não é afetada.
# =============================================================================
set -euo pipefail

RAMO_ESPERADO="feat/faturacao-fiscal-core"

[ -f artisan ] && [ -d Modules/Faturacao ] || { echo "ERRO: corre este script na raiz do repositório Saas1."; exit 1; }

RAMO="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
if [ "$RAMO" != "$RAMO_ESPERADO" ] && [ "${FORCE:-0}" != "1" ]; then
  echo "ERRO: estás na branch '$RAMO'; esperava '$RAMO_ESPERADO'. (FORCE=1 para ignorar)"
  exit 1
fi

command -v python3 >/dev/null || { echo "ERRO: python3 é necessário."; exit 1; }

mkdir -p tests/Support Modules/Atelier/tests/Feature Modules/Faturacao/tests/Feature

echo "==> [1/5] Harness e worker"

# -----------------------------------------------------------------------------
cat > tests/Support/ProcessosConcorrentes.php <<'EOF_TRAIT'
<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Corre várias ações em PROCESSOS PHP separados, cada um com a sua ligação à base
 * de dados, todos libertados pela mesma barreira de tempo. É a diferença para
 * "dois pedidos seguidos no mesmo processo", que não prova concorrência.
 */
trait ProcessosConcorrentes
{
    /** Chamar ANTES de parent::setUp() para nem arrancar a aplicação quando não é para correr. */
    protected function exigirOptInConcorrenciaReal(): void
    {
        if (getenv('SAAS1_CONCORRENCIA_REAL') !== '1') {
            $this->markTestSkipped('Concorrência real: define SAAS1_CONCORRENCIA_REAL=1 (MySQL descartável).');
        }
    }

    /** Chamar DEPOIS de parent::setUp(). */
    protected function exigirMysqlDeTestes(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open indisponível.');
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('A concorrência real só se prova em MySQL (SQLite serializa as escritas).');
        }

        $base = (string) DB::connection()->getDatabaseName();

        if (! str_contains(strtolower($base), 'test')) {
            $this->markTestSkipped("Recuso correr na base de dados '{$base}': o nome tem de conter 'test'.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $argumentos  um elemento por processo
     * @param  array<string, string>  $ambiente  variáveis de ambiente extra para os processos
     * @return list<array<string, mixed>> uma resposta por processo (ok=false se o processo falhou)
     */
    protected function correrEmParalelo(string $acao, array $argumentos, array $ambiente = []): array
    {
        // Margem para todos os processos arrancarem a aplicação antes da barreira.
        $inicio = microtime(true) + 4.0;
        $env = array_merge(getenv(), $ambiente);
        $processos = [];

        foreach ($argumentos as $a) {
            $comando = [
                PHP_BINARY,
                base_path('tests/Support/concorrencia_worker.php'),
                $acao,
                sprintf('%.6F', $inicio),
                json_encode($a, JSON_THROW_ON_ERROR),
            ];

            $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($processo, 'Não foi possível lançar o processo worker.');
            $processos[] = [$processo, $pipes];
        }

        $resultados = [];

        foreach ($processos as [$processo, $pipes]) {
            $saida = (string) stream_get_contents($pipes[1]);
            $erro = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($processo);

            $linhas = array_values(array_filter(array_map('trim', explode("\n", $saida)), 'strlen'));
            $json = $linhas === [] ? null : json_decode((string) end($linhas), true);

            $resultados[] = is_array($json)
                ? $json
                : ['ok' => false, 'erro' => "Saída inválida do worker. stdout: {$saida} stderr: {$erro}"];
        }

        return $resultados;
    }
}
EOF_TRAIT

# -----------------------------------------------------------------------------
cat > tests/Support/concorrencia_worker.php <<'EOF_WORKER'
<?php

declare(strict_types=1);

/*
 * Worker dos testes de concorrência real. Uso (lançado pelo harness):
 *   php tests/Support/concorrencia_worker.php <acao> <barreira_microtime> <json_args>
 * Arranca a aplicação, espera pela barreira e executa UMA ação, imprimindo uma linha JSON.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Modules\Atelier\Http\Controllers\Admin\CampanhaController;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';

$acao = (string) ($argv[1] ?? '');
$barreira = (float) ($argv[2] ?? 0);
$args = json_decode((string) ($argv[3] ?? '{}'), true) ?: [];

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Liga já a base de dados: o custo da ligação não pode entrar depois da barreira.
Illuminate\Support\Facades\DB::connection()->getPdo();

while (microtime(true) < $barreira) {
    usleep(200);
}

try {
    switch ($acao) {
        case 'reenvio':
            Queue::fake();
            app(TenantManager::class)->set((int) $args['empresa_id']);
            app(PermissionRegistrar::class)->setPermissionsTeamId((int) $args['empresa_id']);

            $utilizador = User::withoutGlobalScopes()->findOrFail((int) $args['user_id']);
            $pedido = Request::create('/', 'POST');
            $pedido->setUserResolver(fn () => $utilizador);

            $resposta = app(CampanhaController::class)->enviarAgora($pedido, (int) $args['campanha_id']);

            $resultado = [
                'sucesso' => $resposta->getSession()->get('sucesso'),
                'erro' => $resposta->getSession()->get('erro'),
                'despachados' => Queue::pushed(DispararCampanhaJob::class)->count(),
            ];
            break;

        case 'emitir':
            $fatura = Fatura::withoutGlobalScopes()->findOrFail((int) $args['fatura_id']);
            $emitida = app(FaturaService::class)->emitir($fatura);

            $resultado = [
                'numero' => (int) $emitida->numero_sequencial,
                'documento' => (string) $emitida->numero_documento,
            ];
            break;

        default:
            throw new InvalidArgumentException("Ação desconhecida: {$acao}");
    }

    echo json_encode(['ok' => true] + $resultado, JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => $e::class.': '.$e->getMessage()], JSON_UNESCAPED_UNICODE), PHP_EOL;
}
EOF_WORKER

echo "==> [2/5] Testes"

# -----------------------------------------------------------------------------
cat > Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaRealTest.php <<'EOF_ATL'
<?php

namespace Modules\Atelier\Tests\Feature;

use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Models\CampanhaReenvio;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * ATL-01: oito pedidos de reenvio em PROCESSOS independentes, libertados ao mesmo tempo.
 * Só um pode ser aceite; a garantia final é o índice único na base de dados.
 * Sem RefreshDatabase de propósito: os dados têm de estar comprometidos para os
 * outros processos os verem. Ver cabeçalho do script da fase 4 (segurança).
 */
#[Group('concorrencia-real')]
class ReenvioCampanhaConcorrenciaRealTest extends TestCase
{
    use ProcessosConcorrentes;

    protected Empresa $empresa;

    protected User $admin;

    protected Campanha $campanha;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();

        $sufixo = uniqid();

        $this->empresa = Empresa::create(['nome_comercial' => "Atelier Concorrência {$sufixo}", 'nif' => (string) random_int(400000000, 499999999)]);
        $this->empresa->aderirServicos(['atelier']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->admin = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Admin Concorrência',
            'email' => "admin.concorrencia.{$sufixo}@example.test",
            'password' => bcrypt('segredo-123'),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->empresa->id);
        Role::create(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $this->empresa->id]);
        $this->admin->assignRole('Administrador');

        $cliente = Cliente::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Cliente Concorrência',
            'telefone' => '923456789',
        ]);
        ClienteCrm::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $cliente->id,
            'aceita_marketing' => true,
            'consentimento_em' => now()->subDay(),
        ]);

        $this->campanha = Campanha::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Campanha concorrência',
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'concluida_com_falhas',
            'enviada_em' => now()->subHour(),
        ]);
        CampanhaEnvio::create([
            'campanha_id' => $this->campanha->id,
            'cliente_id' => $cliente->id,
            'canal' => 'whatsapp',
            'estado' => CampanhaEnvio::ESTADO_FALHOU,
            'erro' => 'Falha anterior',
        ]);
    }

    public function test_oito_processos_simultaneos_aceitam_exatamente_um_reenvio(): void
    {
        $argumentos = array_fill(0, 8, [
            'empresa_id' => $this->empresa->id,
            'campanha_id' => $this->campanha->id,
            'user_id' => $this->admin->id,
        ]);

        $resultados = $this->correrEmParalelo('reenvio', $argumentos);

        foreach ($resultados as $r) {
            $this->assertTrue($r['ok'], 'Um processo falhou: '.($r['erro'] ?? '?'));
            $this->assertNull($r['erro'], 'Num clique duplo a resposta nunca pode ser um erro.');
        }

        $aceites = array_filter($resultados, fn ($r) => str_contains((string) $r['sucesso'], 'Envio iniciado'));
        $recusados = array_filter($resultados, fn ($r) => str_contains((string) $r['sucesso'], 'Já existe um reenvio em curso'));

        $this->assertCount(1, $aceites, 'Exatamente um pedido tem de ser aceite.');
        $this->assertCount(7, $recusados, 'Os restantes sete têm de receber "já existe um reenvio em curso".');
        $this->assertSame(1, array_sum(array_column($resultados, 'despachados')), 'Só um job pode ser despachado.');

        $this->assertSame(1, CampanhaReenvio::query()->where('campanha_id', $this->campanha->id)->count());
        $this->assertSame(1, CampanhaReenvio::query()->where('campanha_id', $this->campanha->id)->where('aberto', true)->count());
    }
}
EOF_ATL

# -----------------------------------------------------------------------------
cat > Modules/Faturacao/tests/Feature/EmissaoConcorrenciaRealTest.php <<'EOF_FAT'
<?php

namespace Modules\Faturacao\Tests\Feature;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Serie;
use Modules\Faturacao\Services\FaturaService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * Emissão fiscal sob concorrência real (processos independentes, MySQL):
 * numeração sem lacunas nem repetições, cadeia de hash contínua e idempotência.
 * Sem RefreshDatabase de propósito (ver cabeçalho do script da fase 4).
 */
#[Group('concorrencia-real')]
class EmissaoConcorrenciaRealTest extends TestCase
{
    use ProcessosConcorrentes;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();

        $this->tmp = sys_get_temp_dir().'/emissao-conc-'.uniqid();
        mkdir($this->tmp);
        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->tmp.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmp);
        });

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($this->tmp.'/chave.pem', $pem);

        $this->empresa = Empresa::create(['nome_comercial' => 'Emissão Concorrente '.uniqid(), 'nif' => (string) random_int(500000000, 599999999)]);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    /** Os processos filhos não herdam o config() deste processo: passa-se por variáveis de ambiente. */
    protected function ambiente(): array
    {
        return [
            'FATURACAO_CHAVE_PRIVADA_PATH' => $this->tmp.'/chave.pem',
            'FATURACAO_AGT_ATIVO' => 'false',
        ];
    }

    protected function rascunho(string $descricao): Fatura
    {
        return app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => $descricao, 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14],
        ]);
    }

    public function test_oito_emissoes_simultaneas_geram_numeracao_sem_lacunas_e_cadeia_de_hash_continua(): void
    {
        $rascunhos = [];
        for ($i = 1; $i <= 8; $i++) {
            $rascunhos[] = $this->rascunho("Fatura {$i}");
        }

        $resultados = $this->correrEmParalelo(
            'emitir',
            array_map(fn (Fatura $f) => ['fatura_id' => $f->id], $rascunhos),
            $this->ambiente(),
        );

        foreach ($resultados as $r) {
            $this->assertTrue($r['ok'], 'Uma emissão falhou: '.($r['erro'] ?? '?'));
        }

        $numeros = array_column($resultados, 'numero');
        sort($numeros);
        $this->assertSame(range(1, 8), $numeros, 'A numeração tem de ser 1..8, sem lacunas nem repetições.');

        $emitidas = Fatura::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->where('estado', 'emitida')
            ->orderBy('numero_sequencial')
            ->get()
            ->values();

        $this->assertCount(8, $emitidas);

        foreach ($emitidas as $i => $fatura) {
            $this->assertNotSame('', trim((string) $fatura->hash), 'Fatura emitida sem hash.');

            if ($i === 0) {
                $this->assertNull($fatura->hash_anterior);
            } else {
                $this->assertSame($emitidas[$i - 1]->hash, $fatura->hash_anterior, "Cadeia de hash interrompida na fatura {$fatura->numero_sequencial}.");
            }
        }
    }

    public function test_seis_emissoes_simultaneas_da_mesma_fatura_consomem_um_so_numero(): void
    {
        $fatura = $this->rascunho('Fatura única');

        $resultados = $this->correrEmParalelo(
            'emitir',
            array_fill(0, 6, ['fatura_id' => $fatura->id]),
            $this->ambiente(),
        );

        foreach ($resultados as $r) {
            $this->assertTrue($r['ok'], 'Uma emissão falhou: '.($r['erro'] ?? '?'));
        }

        $this->assertCount(1, array_unique(array_column($resultados, 'documento')), 'Todos os processos têm de ver o mesmo documento.');
        $this->assertSame([1], array_values(array_unique(array_column($resultados, 'numero'))));

        $serie = Serie::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->where('tipo_documento', 'FT')
            ->firstOrFail();

        $this->assertSame(1, (int) $serie->ultimo_numero, 'Emitir é idempotente: só um número pode ser consumido.');
    }
}
EOF_FAT

echo "==> [3/5] Passo novo no CI (job testes-mysql)"

python3 - <<'PY'
import pathlib, sys

caminho = pathlib.Path('.github/workflows/ci.yml')
texto = caminho.read_text(encoding='utf-8')

if 'SAAS1_CONCORRENCIA_REAL' in texto:
    print('  = já aplicado: .github/workflows/ci.yml')
    sys.exit(0)

ancora = ("      # Triggers de imutabilidade, locks e snapshots só se testam em MySQL.\n"
          "      - run: php artisan test --exclude-group=auditoria\n")

if texto.count(ancora) != 1:
    sys.exit('ERRO: âncora do job testes-mysql não encontrada em ci.yml; acrescenta o passo à mão:\n'
             '  SAAS1_CONCORRENCIA_REAL=1 + php artisan migrate:fresh --force + php artisan test --group=concorrencia-real')

novo = ancora + (
    "      # Concorrência real: processos PHP independentes contra o MySQL do serviço.\n"
    "      # Corre sozinha, depois de um migrate:fresh, porque compromete dados (sem transação).\n"
    "      - name: Concorrência real (MySQL)\n"
    "        env:\n"
    "          SAAS1_CONCORRENCIA_REAL: '1'\n"
    "        run: |\n"
    "          php artisan migrate:fresh --force\n"
    "          php artisan test --group=concorrencia-real\n"
)

caminho.write_text(texto.replace(ancora, novo, 1), encoding='utf-8')
print('  + .github/workflows/ci.yml')
PY

echo "==> [4/5] Sintaxe e formatação (o CI corre pint --test nos ficheiros alterados)"
FICHEIROS=(
  tests/Support/ProcessosConcorrentes.php
  tests/Support/concorrencia_worker.php
  Modules/Atelier/tests/Feature/ReenvioCampanhaConcorrenciaRealTest.php
  Modules/Faturacao/tests/Feature/EmissaoConcorrenciaRealTest.php
)
if command -v php >/dev/null; then
  for f in "${FICHEIROS[@]}"; do php -l "$f" >/dev/null && echo "  ok  $f"; done
fi
if [ -x vendor/bin/pint ]; then
  vendor/bin/pint "${FICHEIROS[@]}" || true
else
  echo "  (vendor/bin/pint não encontrado: corre 'composer install' e depois vendor/bin/pint <ficheiros>)"
fi

echo "==> [5/5] Execução"
if [ "${RUN:-0}" = "1" ]; then
  : "${DB_CONNECTION:?RUN=1 exige DB_CONNECTION=mysql}"
  : "${DB_DATABASE:?RUN=1 exige DB_DATABASE}"
  case "$DB_DATABASE" in
    *test*) ;;
    *) echo "ERRO: DB_DATABASE='$DB_DATABASE' não contém 'test'. Recuso fazer migrate:fresh."; exit 1;;
  esac
  [ "$DB_CONNECTION" = "mysql" ] || { echo "ERRO: DB_CONNECTION tem de ser mysql."; exit 1; }
  echo "  migrate:fresh em '$DB_DATABASE' (base de testes)"
  php artisan migrate:fresh --force
  SAAS1_CONCORRENCIA_REAL=1 php artisan test --group=concorrencia-real
else
  echo "  (não executado) Para correr localmente, com um MySQL descartável:"
  echo "    DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=saas_test DB_USERNAME=root DB_PASSWORD=... RUN=1 bash $0"
  echo "  Na suite normal estes testes saltam sozinhos (SKIPPED)."
fi

git status --short

cat <<'MSG'

Concluído. Se algum teste FALHAR, não o "arranjes": a falha é a prova de um problema
real de concorrência (deadlock, número repetido, cadeia de hash partida). Diz-me a
mensagem e corrigimos o código, não o teste.
MSG
