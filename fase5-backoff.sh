quem#!/usr/bin/env bash
# Fase 5 (2.º commit) — F5-06: backoff por documento nas falhas de rede/AGT.
# Uso (raiz do projeto, no Codespace):  bash fase5-backoff.sh
# Só faz commit/push se a suite do módulo Faturação passar.
set -euo pipefail

SCRIPT="$(basename "$0")"
cd "$(git rev-parse --show-toplevel)"
[ -f artisan ] || { echo "ERRO: corre na raiz do projeto Laravel."; exit 1; }
command -v python3 >/dev/null || { echo "ERRO: python3 não encontrado."; exit 1; }

if [ -n "$(git status --porcelain | grep -v "$SCRIPT" || true)" ]; then
  echo "ERRO: há alterações por commitar. Faz commit ou 'git stash' e repete."
  exit 1
fi

git fetch origin
NOVO_PR=0
if git merge-base --is-ancestor origin/fix/fase5-saft-agt origin/main 2>/dev/null; then
  RAMO="fix/fase5-backoff"
  echo "==> PR anterior já está na main: novo ramo $RAMO a partir da origin/main"
  git checkout -B "$RAMO" origin/main
  NOVO_PR=1
else
  RAMO="fix/fase5-saft-agt"
  echo "==> A continuar no ramo $RAMO (mesmo PR)"
  git checkout -B "$RAMO" "origin/$RAMO"
fi

echo "==> 1/5 Migration + correções"
cat > Modules/Faturacao/database/migrations/2026_11_16_000032_add_proxima_tentativa_to_agt_submissoes.php <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Backoff por documento nas falhas transitórias de envio à AGT (Fase 5, F5-06). */
    public function up(): void
    {
        Schema::table('agt_submissoes', function (Blueprint $table) {
            $table->timestamp('proxima_tentativa_em')->nullable()->after('ultima_consulta_em');
            $table->index(['empresa_id', 'estado', 'proxima_tentativa_em'], 'agt_subm_proxima_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agt_submissoes', function (Blueprint $table) {
            $table->dropIndex('agt_subm_proxima_idx');
            $table->dropColumn('proxima_tentativa_em');
        });
    }
};
PHP

python3 - <<'PY'
import pathlib
import sys

MOD = "Modules/Faturacao/"


def sub(path, old, new):
    p = pathlib.Path(path)
    texto = p.read_text(encoding="utf-8")
    n = texto.count(old)
    if n != 1:
        sys.exit(f"ERRO: {path}: âncora encontrada {n}x (esperado 1):\n{old[:160]}")
    p.write_text(texto.replace(old, new), encoding="utf-8")
    print(f"  ok  {path}")


# ---------------------------------------------------------------- Modelo
mod = MOD + "app/Models/AgtSubmissao.php"
sub(mod,
    r'''        'validado_em',
    ];
''',
    r'''        'validado_em',
        'proxima_tentativa_em',
    ];
''')
sub(mod,
    r'''            'validado_em' => 'datetime',
''',
    r'''            'validado_em' => 'datetime',
            'proxima_tentativa_em' => 'datetime',
''')

# ---------------------------------------------------------------- Serviço
svc = MOD + "app/Services/AgtSubmissaoService.php"

# Só entram no lote os documentos cuja hora de nova tentativa já chegou.
sub(svc,
    r'''            ->where('estado', AgtSubmissao::PENDENTE)
            ->whereNotNull('submission_uuid')
''',
    r'''            ->where('estado', AgtSubmissao::PENDENTE)
            ->where(fn ($q) => $q->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', now()))
            ->whereNotNull('submission_uuid')
''')
sub(svc,
    r'''        $consultaLote = AgtSubmissao::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', AgtSubmissao::PENDENTE);
''',
    r'''        $consultaLote = AgtSubmissao::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', AgtSubmissao::PENDENTE)
            ->where(fn ($q) => $q->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', now()));
''')

# Reenvio manual limpa o backoff.
sub(svc,
    r'''$dados = ['estado' => AgtSubmissao::PENDENTE, 'tentativas' => 0, 'erros' => null, 'request_id' => null];''',
    r'''$dados = ['estado' => AgtSubmissao::PENDENTE, 'tentativas' => 0, 'erros' => null, 'request_id' => null, 'proxima_tentativa_em' => null];''')

# Falha transitória: espera exponencial em vez de uma tentativa por minuto.
sub(svc,
    r'''            $tentativas = $submissao->tentativas + 1;
            $dados = ['tentativas' => $tentativas];

            if ($tentativas >= $maximo) {
                $dados += ['estado' => AgtSubmissao::ERRO, 'erros' => [['codigo' => 'REDE', 'descricao' => $motivo]]];
''',
    r'''            $tentativas = $submissao->tentativas + 1;
            $dados = ['tentativas' => $tentativas, 'proxima_tentativa_em' => now()->addSeconds($this->esperaAteNovaTentativa($tentativas))];

            if ($tentativas >= $maximo) {
                $dados += ['estado' => AgtSubmissao::ERRO, 'erros' => [['codigo' => 'REDE', 'descricao' => $motivo]]];
                $dados['proxima_tentativa_em'] = null;
''')

sub(svc,
    r'''    /**
     * @param  array<int, AgtSubmissao>  $grupo  submissões do mesmo pedido (requestID)
''',
    r'''    /** Espera exponencial: base, 2×base, 4×base… até `agt.backoff_maximo` (segundos). */
    protected function esperaAteNovaTentativa(int $tentativas): int
    {
        $base = max(0, (int) config('faturacao.agt.backoff_base', 60));
        $maximo = max(0, (int) config('faturacao.agt.backoff_maximo', 1800));

        return min($maximo, $base * (2 ** max(0, $tentativas - 1)));
    }

    /**
     * @param  array<int, AgtSubmissao>  $grupo  submissões do mesmo pedido (requestID)
''')

# ---------------------------------------------------------------- Config
cfg = MOD + "config/config.php"
sub(cfg, r'''(int) env('FATURACAO_AGT_MAX_TENTATIVAS', 5),''', r'''(int) env('FATURACAO_AGT_MAX_TENTATIVAS', 8),''')
sub(cfg,
    r'''        'consulta_intervalo_intervencao' => (int) env('FATURACAO_AGT_CONSULTA_INTERVALO_INTERVENCAO', 3600), // segundos entre consultas de pedidos "a aguardar intervenção"
''',
    r'''        'consulta_intervalo_intervencao' => (int) env('FATURACAO_AGT_CONSULTA_INTERVALO_INTERVENCAO', 3600), // segundos entre consultas de pedidos "a aguardar intervenção"
        'backoff_base' => (int) env('FATURACAO_AGT_BACKOFF_BASE', 60), // 1.ª espera após falha de envio (dobra a cada tentativa)
        'backoff_maximo' => (int) env('FATURACAO_AGT_BACKOFF_MAXIMO', 1800), // teto da espera entre tentativas (segundos)
''')

# ---------------------------------------------------------------- Testes existentes: sem espera entre tentativas
sub(MOD + "tests/Feature/AgtSubmissaoTest.php",
    r'''            'faturacao.saft_numero_certificado' => 'C_134',
        ]);
''',
    r'''            'faturacao.saft_numero_certificado' => 'C_134',
            'faturacao.agt.backoff_base' => 0, // estes testes repetem o envio de seguida
        ]);
''')

# ---------------------------------------------------------------- Testes novos
t = MOD + "tests/Feature/AgtRobustezFase5Test.php"
sub(t, r'''use Illuminate\Http\Client\Request;
''', r'''use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
''')
sub(t, r'''    protected string $dirChaves;
''', r'''    protected string $dirChaves;

    protected bool $falharRede = false;
''')
sub(t,
    r'''            '*registarFactura' => Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
''',
    r'''            '*registarFactura' => fn () => $this->falharRede
                ? throw new ConnectionException('timeout')
                : Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
''')
sub(t,
    r'''        $this->expectException(AgtException::class);

        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);
    }
''',
    r'''        $this->expectException(AgtException::class);

        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);
    }

    public function test_falha_de_rede_aplica_backoff_e_nao_reenvia_antes_da_hora(): void
    {
        $this->emitirFatura();

        $this->falharRede = true;
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame(AgtSubmissao::PENDENTE, $submissao->estado);
        $this->assertNotNull($submissao->proxima_tentativa_em);
        $this->assertTrue($submissao->proxima_tentativa_em->isFuture());

        $antes = $this->pedidosPara('registarFactura')->count();

        $this->falharRede = false;
        app(AgtSubmissaoService::class)->enviarPendentes();
        $this->assertCount($antes, $this->pedidosPara('registarFactura'), 'Antes da hora de nova tentativa não há pedido à AGT.');

        $submissao->update(['proxima_tentativa_em' => now()->subSecond()]);

        $this->assertSame(1, app(AgtSubmissaoService::class)->enviarPendentes()['enviados']);
    }

    public function test_esgotadas_as_tentativas_passa_a_erro_sem_nova_tentativa_agendada(): void
    {
        config(['faturacao.agt.max_tentativas' => 2, 'faturacao.agt.backoff_base' => 0]);
        $this->emitirFatura();

        $this->falharRede = true;
        app(AgtSubmissaoService::class)->enviarPendentes();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame(AgtSubmissao::ERRO, $submissao->estado);
        $this->assertSame('REDE', $submissao->erros[0]['codigo']);
        $this->assertNull($submissao->proxima_tentativa_em);
    }
''')

# ---------------------------------------------------------------- Documentação
sub("docs/auditoria-fase5-saft-agt.md",
    r'''Pede backoff por documento (coluna `proxima_tentativa_em`) | `AgtSubmissaoService` | DECISÃO |''',
    r'''Pede backoff por documento (coluna `proxima_tentativa_em`) | `AgtSubmissaoService` | CORRIGIDO (backoff 1, 2, 4… min até 30 min; 8 tentativas) |''')
PY

echo "==> 2/5 Formatação (Pint)"
if [ -x vendor/bin/pint ]; then vendor/bin/pint --dirty; else echo "  (pint não instalado — a saltar)"; fi

echo "==> 3/5 Migrations em base limpa + testes do módulo"
php artisan migrate:fresh --env=testing --force >/dev/null 2>&1 || echo "  (migrate:fresh --env=testing ignorado; os testes usam RefreshDatabase)"
if ! php artisan test --filter=Faturacao; then
  echo
  echo "TESTES FALHARAM — nada foi commitado. Envia-me o output acima."
  echo "Para repor:  git reset --hard && git clean -fd -e $SCRIPT"
  exit 1
fi

echo "==> 4/5 Commit e push"
git add -A -- . ":(exclude)$SCRIPT"
git commit -m "fix(faturacao): fase 5 — backoff por documento nas falhas de envio à AGT (F5-06)" \
  -m "Nova coluna agt_submissoes.proxima_tentativa_em; espera exponencial (base 60 s, teto 30 min); 8 tentativas por omissão."
git push -u origin "$RAMO"

echo "==> 5/5 PR"
if [ "$NOVO_PR" = "1" ] && command -v gh >/dev/null; then
  gh pr create --base main --head "$RAMO" \
    --title "Fase 5 — backoff por documento nas falhas de envio à AGT (F5-06)" \
    --body "Fecha F5-06 de docs/auditoria-fase5-saft-agt.md: uma falha de rede deixa de converter todos os pendentes em ERRO ao fim de ~5 min. Migration: agt_submissoes.proxima_tentativa_em."
else
  echo "Commit adicionado ao PR existente."
fi

rm -f -- "$SCRIPT"
echo "Concluído."
