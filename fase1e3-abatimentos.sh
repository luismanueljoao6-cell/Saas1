#!/usr/bin/env bash
# PR #33 — Fases 1 e 3: abatimentos de adiantamentos (FA -> FT).
#
# Fase 3 (idempotência concorrente): o serviço já trata a corrida (apanha a violação da chave
#   única e devolve o original); faltava PROVAR esse caminho — o script acrescenta os testes.
# Fase 1 (integridade fiscal): a representação fiscal do abatimento depende da especificação da
#   AGT e NÃO se resolve em código. O que se pode fazer, e este script faz:
#     1. reconciliar os abatimentos na pré-validação do SAF-T (FA/FT/cliente/empresa/valores);
#     2. em produção, recusar o SAF-T de períodos com abatimentos enquanto a representação fiscal
#        não for confirmada (FATURACAO_ABATIMENTOS_ADIANTAMENTO_CONFIRMADOS).
#
# Correr na raiz do repositório Saas1, na branch feat/faturacao-fiscal-core. Não faz commit.
set -euo pipefail

RAMO="$(git rev-parse --abbrev-ref HEAD)"
if [ "$RAMO" != "feat/faturacao-fiscal-core" ]; then
  echo "Branch errada: $RAMO (esperado feat/faturacao-fiscal-core)." >&2
  exit 1
fi

for f in \
  Modules/Faturacao/app/Services/SafTPreValidacao.php \
  Modules/Faturacao/config/config.php; do
  [ -f "$f" ] || { echo "Ficheiro em falta: $f" >&2; exit 1; }
done

python3 - <<'PY'
import sys

def patch(path, trocas):
    texto = open(path, encoding='utf-8').read()
    for antigo, novo in trocas:
        if texto.count(antigo) != 1:
            sys.exit(f"Âncora não encontrada exatamente uma vez em {path}:\n{antigo[:160]}")
        texto = texto.replace(antigo, novo)
    open(path, 'w', encoding='utf-8').write(texto)

# ---------------------------------------------------------------------------
# Configuração: salvaguarda explícita (desligada por omissão)
# ---------------------------------------------------------------------------
patch('Modules/Faturacao/config/config.php', [
    (
        r"""    'nota_debito_liquida_iva' => (bool) env('FATURACAO_ND_LIQUIDA_IVA', false),
""",
        r"""    'nota_debito_liquida_iva' => (bool) env('FATURACAO_ND_LIQUIDA_IVA', false),

    /*
    | Abatimento de adiantamentos (FA) em faturas finais (FT). Hoje é contabilidade interna:
    | não gera documento fiscal nem entra na comunicação AGT/SAF-T. Enquanto a representação
    | fiscal não estiver definida com a AGT e implementada, em produção a pré-validação do
    | SAF-T recusa períodos com abatimentos. NÃO ponhas true só para desbloquear a exportação:
    | true afirma que a representação fiscal existe (ver docs/homologacao-agt.md, ponto H3).
    */
    'abatimentos_adiantamento_confirmados' => (bool) env('FATURACAO_ABATIMENTOS_ADIANTAMENTO_CONFIRMADOS', false),
""",
    ),
])

# ---------------------------------------------------------------------------
# Pré-validação do SAF-T: reconciliação dos abatimentos
# ---------------------------------------------------------------------------
patch('Modules/Faturacao/app/Services/SafTPreValidacao.php', [
    (
        r"""use Illuminate\Support\Carbon;
""",
        r"""use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
""",
    ),
    (
        r"""        foreach ($this->validarRecibos($empresa, $de, $ate) as $erro) {
            $erros[] = $erro;
        }
""",
        r"""        foreach ($this->validarRecibos($empresa, $de, $ate) as $erro) {
            $erros[] = $erro;
        }

        foreach ($this->validarAbatimentos($empresa, $de, $ate) as $erro) {
            $erros[] = $erro;
        }
""",
    ),
    (
        r"""    /**
     * Configuração coerente: toda a taxa aceite na emissão tem de ter código SAF-T,
""",
        r"""    /**
     * Abatimentos de adiantamentos (FA -> FT) registados no período.
     *
     * São contabilidade interna: não geram documento fiscal nem entram no SAF-T. Por isso (1) reconcilia-se
     * o registo interno com os documentos — mesma empresa, tipos FA/FT, mesmo cliente, totais — e (2) em
     * produção o SAF-T recusa períodos com abatimentos até a representação fiscal estar confirmada.
     *
     * @return list<string>
     */
    public function validarAbatimentos(Empresa $empresa, string $de, string $ate): array
    {
        $erros = [];

        $abatimentos = DB::table('adiantamento_abatimentos')
            ->where('empresa_id', $empresa->id)
            ->whereBetween('created_at', [$de.' 00:00:00', $ate.' 23:59:59'])
            ->orderBy('id')
            ->get();

        if ($abatimentos->isEmpty()) {
            return [];
        }

        $faturas = [];
        $buscar = function (int $id) use ($empresa, &$faturas): ?Fatura {
            return $faturas[$id] ??= Fatura::withoutGlobalScopes()->where('empresa_id', $empresa->id)->find($id);
        };

        $faVerificados = [];
        $ftVerificadas = [];

        foreach ($abatimentos as $abatimento) {
            $ref = "Abatimento #{$abatimento->id}";
            $fa = $buscar((int) $abatimento->fatura_adiantamento_id);
            $ft = $buscar((int) $abatimento->fatura_final_id);

            if (! $fa) {
                $erros[] = "{$ref}: o adiantamento #{$abatimento->fatura_adiantamento_id} não existe nesta empresa.";
            }

            if (! $ft) {
                $erros[] = "{$ref}: a fatura final #{$abatimento->fatura_final_id} não existe nesta empresa.";
            }

            if (! $fa || ! $ft) {
                continue;
            }

            if ($fa->tipoDocumentoFiscal() !== 'FA') {
                $erros[] = "{$ref}: o documento {$fa->numero_documento} não é uma fatura de adiantamento (FA).";
            }

            if ($ft->tipoDocumentoFiscal() !== 'FT') {
                $erros[] = "{$ref}: o documento {$ft->numero_documento} não é uma fatura (FT).";
            }

            if ((int) $fa->cliente_id !== (int) $ft->cliente_id) {
                $erros[] = "{$ref}: o adiantamento {$fa->numero_documento} e a fatura final {$ft->numero_documento} são de clientes diferentes.";
            }

            if (! isset($faVerificados[$fa->id])) {
                $faVerificados[$fa->id] = true;

                $abatido = (int) DB::table('adiantamento_abatimentos')
                    ->where('empresa_id', $empresa->id)
                    ->where('fatura_adiantamento_id', $fa->id)
                    ->sum('valor_centimos');

                $resumo = app(ReciboService::class)->resumoDaFatura($fa);
                $liquido = (int) $resumo['recebido'] - (int) $resumo['creditado'];

                if ($abatido > $liquido) {
                    $erros[] = "Adiantamento {$fa->numero_documento}: o total abatido (".Dinheiro::formatar($abatido).') excede o recebido líquido de notas de crédito ('.Dinheiro::formatar($liquido).').';
                }
            }

            if (! isset($ftVerificadas[$ft->id])) {
                $ftVerificadas[$ft->id] = true;

                $abatido = (int) DB::table('adiantamento_abatimentos')
                    ->where('empresa_id', $empresa->id)
                    ->where('fatura_final_id', $ft->id)
                    ->sum('valor_centimos');

                if ($abatido > Dinheiro::centimos($ft->valor_total)) {
                    $erros[] = "Fatura final {$ft->numero_documento}: o total abatido (".Dinheiro::formatar($abatido).') excede o total da fatura ('.Dinheiro::formatar(Dinheiro::centimos($ft->valor_total)).').';
                }
            }
        }

        if (app()->isProduction() && ! config('faturacao.abatimentos_adiantamento_confirmados')) {
            $erros[] = "O período tem {$abatimentos->count()} abatimento(s) de adiantamentos (FA -> FT), que hoje são só contabilidade interna: "
                .'a representação fiscal (AGT/SAF-T) ainda não está confirmada (FATURACAO_ABATIMENTOS_ADIANTAMENTO_CONFIRMADOS; ver docs/homologacao-agt.md, ponto H3).';
        }

        return $erros;
    }

    /**
     * Configuração coerente: toda a taxa aceite na emissão tem de ter código SAF-T,
""",
    ),
])
print('Patches aplicados.')
PY

# ---------------------------------------------------------------------------
# Fase 3: provar a corrida de idempotência do abatimento
# ---------------------------------------------------------------------------
cat > Modules/Faturacao/tests/Feature/AbatimentoIdempotenciaConcorrenteTest.php <<'PHP'
<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AbatimentoAdiantamentoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Tests\TestCase;

/**
 * Fase 3 da PR #33 — idempotência do abatimento sob concorrência.
 *
 * A corrida «perdida» é simulada: imediatamente antes do INSERT do serviço, outra
 * «requisição» grava a mesma chave. Prova o caminho de recuperação (violação da chave
 * única -> devolve o original, ou rejeita se os dados diferirem). Não substitui um ensaio
 * com processos paralelos reais em MySQL.
 */
class AbatimentoIdempotenciaConcorrenteTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/abat-idem-'.uniqid();
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

        config([
            'faturacao.chaves_dir' => $this->tmp,
            'faturacao.chave_privada_path' => $this->tmp.'/chave.pem',
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja A', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente A', 'nif' => null]);
    }

    /** FA de 1140.00 emitido e totalmente recebido. */
    protected function adiantamentoRecebido(): Fatura
    {
        $faturas = app(FaturaService::class);
        $fa = $faturas->emitir($faturas->criarRascunhoAdiantamento($this->empresa, $this->cliente, [
            ['descricao' => 'Adiantamento', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '1140.00', 'dinheiro', $fa->id);

        return Fatura::withoutGlobalScopes()->findOrFail($fa->id);
    }

    /** FT de 1140.00 emitida. */
    protected function faturaFinal(): Fatura
    {
        $faturas = app(FaturaService::class);
        $ft = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Venda final', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        return Fatura::withoutGlobalScopes()->findOrFail($ft->id);
    }

    /**
     * Grava, imediatamente antes do INSERT do serviço, uma linha concorrente com a mesma chave.
     * O guarda evita recursão (o próprio insert do concorrente também passa por aqui).
     */
    protected function concorrenteGravaAntesDoInsert(Fatura $fa, Fatura $ft, int $centimos, string $chave): void
    {
        $conexao = DB::connection();

        if (! method_exists($conexao, 'beforeExecuting')) {
            $this->markTestSkipped('Esta versão do Laravel não tem Connection::beforeExecuting.');
        }

        $feito = false;

        $conexao->beforeExecuting(function (string $query) use (&$feito, $fa, $ft, $centimos, $chave): void {
            if ($feito || ! str_contains($query, 'adiantamento_abatimentos') || ! str_starts_with(strtolower(ltrim($query)), 'insert')) {
                return;
            }

            $feito = true;

            DB::table('adiantamento_abatimentos')->insert([
                'empresa_id' => $this->empresa->id,
                'fatura_adiantamento_id' => $fa->id,
                'fatura_final_id' => $ft->id,
                'valor_centimos' => $centimos,
                'chave' => $chave,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_corrida_perdida_com_os_mesmos_dados_devolve_o_abatimento_original(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        $this->concorrenteGravaAntesDoInsert($fa, $ft, 50000, 'chave-corrida-0001');

        $resultado = app(AbatimentoAdiantamentoService::class)
            ->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-corrida-0001');

        $linhas = DB::table('adiantamento_abatimentos')->where('empresa_id', $this->empresa->id)->get();

        $this->assertCount(1, $linhas, 'A corrida nunca pode duplicar o abatimento.');
        $this->assertSame((int) $linhas->first()->id, (int) $resultado['id']);
        $this->assertSame(50000, (int) $resultado['valor_centimos']);
    }

    public function test_corrida_perdida_com_dados_diferentes_e_rejeitada_sem_duplicar(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        $this->concorrenteGravaAntesDoInsert($fa, $ft, 10000, 'chave-corrida-0002');

        try {
            app(AbatimentoAdiantamentoService::class)
                ->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-corrida-0002');

            $this->fail('Devia ter rejeitado a chave reutilizada com dados diferentes.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Chave de idempotência reutilizada com dados diferentes.', $e->getMessage());
        }

        $linhas = DB::table('adiantamento_abatimentos')->where('empresa_id', $this->empresa->id)->get();

        $this->assertCount(1, $linhas);
        $this->assertSame(10000, (int) $linhas->first()->valor_centimos, 'O registo do concorrente não pode ser alterado.');
    }

    public function test_dois_abatimentos_com_chaves_diferentes_nunca_ultrapassam_o_adiantamento(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        $servico = app(AbatimentoAdiantamentoService::class);

        $servico->abater($this->empresa->id, $fa->id, $ft->id, '800.00', 'chave-soma-0001');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('excede o adiantamento');

        $servico->abater($this->empresa->id, $fa->id, $ft->id, '400.00', 'chave-soma-0002');
    }
}
PHP

# ---------------------------------------------------------------------------
# Fase 1: reconciliação dos abatimentos e salvaguarda de produção
# ---------------------------------------------------------------------------
cat > Modules/Faturacao/tests/Feature/AbatimentoReconciliacaoSafTTest.php <<'PHP'
<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AbatimentoAdiantamentoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Services\SafTPreValidacao;
use Tests\TestCase;

/**
 * Fase 1 da PR #33 — reconciliação entre abatimentos e documentos na pré-validação do SAF-T,
 * e salvaguarda de produção enquanto a representação fiscal do abatimento não estiver confirmada.
 *
 * Os casos «corrompidos» gravam diretamente na tabela, como faria uma operação fora da aplicação.
 */
class AbatimentoReconciliacaoSafTTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/abat-rec-'.uniqid();
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

        config([
            'faturacao.chaves_dir' => $this->tmp,
            'faturacao.chave_privada_path' => $this->tmp.'/chave.pem',
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja A', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente A', 'nif' => null]);
    }

    protected function adiantamentoRecebido(): Fatura
    {
        $faturas = app(FaturaService::class);
        $fa = $faturas->emitir($faturas->criarRascunhoAdiantamento($this->empresa, $this->cliente, [
            ['descricao' => 'Adiantamento', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '1140.00', 'dinheiro', $fa->id);

        return Fatura::withoutGlobalScopes()->findOrFail($fa->id);
    }

    protected function faturaFinal(?Empresa $empresa = null, ?Cliente $cliente = null): Fatura
    {
        $faturas = app(FaturaService::class);
        $ft = $faturas->emitir($faturas->criarRascunho($empresa ?? $this->empresa, $cliente ?? $this->cliente, [
            ['descricao' => 'Venda final', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        return Fatura::withoutGlobalScopes()->findOrFail($ft->id);
    }

    /** Grava um abatimento diretamente na tabela, sem passar pelo serviço (dados «corrompidos»). */
    protected function abatimentoDireto(int $empresaId, int $faId, int $ftId, int $centimos, string $chave): void
    {
        DB::table('adiantamento_abatimentos')->insert([
            'empresa_id' => $empresaId,
            'fatura_adiantamento_id' => $faId,
            'fatura_final_id' => $ftId,
            'valor_centimos' => $centimos,
            'chave' => $chave,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    protected function validar(): array
    {
        return app(SafTPreValidacao::class)->validarAbatimentos($this->empresa, now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString());
    }

    /** @param  list<string>  $erros */
    protected function contem(array $erros, string $trecho): bool
    {
        return count(array_filter($erros, fn ($e) => str_contains($e, $trecho))) > 0;
    }

    public function test_abatimento_consistente_reconcilia_sem_erros(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        app(AbatimentoAdiantamentoService::class)->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-rec-0001');

        $this->assertSame([], $this->validar());
        $this->assertSame([], app(SafTPreValidacao::class)->validar($this->empresa, now()->startOfYear(), now()->endOfYear()));
    }

    public function test_periodo_sem_abatimentos_nao_gera_erros(): void
    {
        $this->adiantamentoRecebido();

        $this->assertSame([], $this->validar());
    }

    public function test_abatimento_acima_do_recebido_e_do_total_da_fatura_final_e_detetado(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        $this->abatimentoDireto($this->empresa->id, $fa->id, $ft->id, 200000, 'chave-rec-0002');

        $erros = $this->validar();

        $this->assertTrue($this->contem($erros, 'o total abatido'), implode("\n", $erros));
        $this->assertTrue($this->contem($erros, 'Adiantamento '), implode("\n", $erros));
        $this->assertTrue($this->contem($erros, 'Fatura final '), implode("\n", $erros));
    }

    public function test_abatimento_entre_clientes_diferentes_e_detetado(): void
    {
        $fa = $this->adiantamentoRecebido();
        $outro = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Outro cliente', 'nif' => null]);
        $ft = $this->faturaFinal($this->empresa, $outro);
        $this->abatimentoDireto($this->empresa->id, $fa->id, $ft->id, 10000, 'chave-rec-0003');

        $this->assertTrue($this->contem($this->validar(), 'clientes diferentes'));
    }

    public function test_abatimento_que_liga_faturas_de_outra_empresa_e_detetado(): void
    {
        $empresaB = Empresa::create(['nome_comercial' => 'Loja B', 'nif' => '5001636864']);
        $clienteB = Cliente::create(['empresa_id' => $empresaB->id, 'nome' => 'Cliente B', 'nif' => null]);

        $fa = $this->adiantamentoRecebido();
        $ftB = $this->faturaFinal($empresaB, $clienteB);
        $this->abatimentoDireto($this->empresa->id, $fa->id, $ftB->id, 10000, 'chave-rec-0004');

        $this->assertTrue($this->contem($this->validar(), 'não existe nesta empresa'));
    }

    public function test_abatimento_com_documento_do_tipo_errado_e_detetado(): void
    {
        $fa = $this->adiantamentoRecebido();
        $outroFa = $this->adiantamentoRecebido();
        $this->abatimentoDireto($this->empresa->id, $fa->id, $outroFa->id, 10000, 'chave-rec-0005');

        $this->assertTrue($this->contem($this->validar(), 'não é uma fatura (FT)'));
    }

    public function test_em_producao_o_saft_recusa_abatimentos_ate_a_representacao_fiscal_ser_confirmada(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        app(AbatimentoAdiantamentoService::class)->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-rec-0006');

        // Fora de produção nada bloqueia.
        $this->assertSame([], $this->validar());

        $this->app['env'] = 'production';

        $erros = $this->validar();
        $this->assertTrue($this->contem($erros, 'representação fiscal'), implode("\n", $erros));

        config(['faturacao.abatimentos_adiantamento_confirmados' => true]);
        $this->assertSame([], $this->validar());
    }
}
PHP

# ---------------------------------------------------------------------------
# Documentação: H3 passa a ter salvaguarda ativa
# ---------------------------------------------------------------------------
if [ -f docs/homologacao-agt.md ] && ! grep -q 'FATURACAO_ABATIMENTOS_ADIANTAMENTO_CONFIRMADOS' docs/homologacao-agt.md; then
cat >> docs/homologacao-agt.md <<'MD'

## 4. H3 — salvaguarda ativa (adiantamentos e abatimentos)

- Os abatimentos continuam a ser **contabilidade interna**: não geram documento fiscal nem entram na comunicação AGT/SAF-T.
- A pré-validação do SAF-T reconcilia cada abatimento (mesma empresa, FA→FT, mesmo cliente, totais) e, **em produção**,
  recusa períodos com abatimentos enquanto `FATURACAO_ABATIMENTOS_ADIANTAMENTO_CONFIRMADOS` for `false` (por omissão).
- `true` afirma que a representação fiscal está definida **e implementada**. Só se muda depois de fechar o ponto H3
  com a especificação da AGT; nunca para desbloquear a exportação.
- Testes: `AbatimentoReconciliacaoSafTTest`, `AbatimentoIdempotenciaConcorrenteTest`.
MD
fi

FICHEIROS=(
  Modules/Faturacao/app/Services/SafTPreValidacao.php
  Modules/Faturacao/config/config.php
  Modules/Faturacao/tests/Feature/AbatimentoIdempotenciaConcorrenteTest.php
  Modules/Faturacao/tests/Feature/AbatimentoReconciliacaoSafTTest.php
)

for f in "${FICHEIROS[@]}"; do php -l "$f"; done

vendor/bin/pint "${FICHEIROS[@]}"

php artisan test --filter='AbatimentoIdempotenciaConcorrenteTest|AbatimentoReconciliacaoSafTTest|SafTPreValidacaoTest|SafTRecibosTest|AdiantamentoNotaCreditoTest|FluxoFiscalIntegradoTest|GerarSafTJobTest'

echo
git status --short
echo
echo "Se os testes estiverem verdes:"
echo "  git add Modules/Faturacao docs && git commit -m \"fix(faturacao): reconciliação e salvaguarda dos abatimentos de adiantamentos (fases 1 e 3 da PR #33)\" && git push"
