#!/usr/bin/env bash
# Fase 5 — SAF-T Angola e comunicação com a AGT: correções + PR dedicado.
# Uso (raiz do projeto, no Codespace):  bash fase5-saft-agt.sh
# Não altera nada na main: trabalha no ramo fix/fase5-saft-agt e só faz
# commit/push/PR se a suite do módulo Faturação passar.
set -euo pipefail

RAMO="fix/fase5-saft-agt"
SCRIPT="$(basename "$0")"

cd "$(git rev-parse --show-toplevel)"
[ -f artisan ] || { echo "ERRO: corre este script na raiz do projeto Laravel."; exit 1; }
command -v python3 >/dev/null || { echo "ERRO: python3 não encontrado."; exit 1; }

if [ -n "$(git status --porcelain | grep -v "$SCRIPT" || true)" ]; then
  echo "ERRO: há alterações por commitar. Faz commit ou 'git stash' e repete."
  exit 1
fi

echo "==> 1/6 Ramo $RAMO a partir da origin/main"
git fetch origin main
git checkout -B "$RAMO" origin/main

echo "==> 2/6 A aplicar as correções (falha se alguma âncora não coincidir)"
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


# ---------------------------------------------------------------- AgtSubmissaoService
svc = MOD + "app/Services/AgtSubmissaoService.php"

sub(svc,
    r'''use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Models\AgtSubmissao;
''',
    r'''use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Models\AgtSubmissao;
''')

# F5-11: pedidos "a aguardar intervenção" deixam de ser consultados de minuto a minuto.
sub(svc,
    r'''            ->get()
            ->groupBy(fn (AgtSubmissao $s) => $s->empresa_id.'|'.$s->request_id);
''',
    r'''            ->get()
            ->filter(fn (AgtSubmissao $s) => $this->consultaDevida($s, $intervalo))
            ->groupBy(fn (AgtSubmissao $s) => $s->empresa_id.'|'.$s->request_id);
''')

# F5-09: a chave (ou o NIF) de UMA empresa não pode parar a consulta de todas.
sub(svc,
    r'''                $parcial = $this->aguardar($grupo->all(), ['validos' => 0, 'invalidos' => 0, 'erros' => 0, 'pendentes' => 0]);
            }
''',
    r'''                $parcial = $this->aguardar($grupo->all(), ['validos' => 0, 'invalidos' => 0, 'erros' => 0, 'pendentes' => 0]);
            } catch (AssinaturaFiscalException $e) {
                // A chave (ou o NIF) de UMA empresa em falta/inválida não pode parar a consulta de todas as outras.
                Log::error('Consulta AGT adiada: chave de assinatura indisponível.', [
                    'empresa_id' => $grupo->first()->empresa_id,
                    'request_id' => $grupo->first()->request_id,
                    'erro' => $e->getMessage(),
                ]);

                $parcial = $this->aguardar($grupo->all(), ['validos' => 0, 'invalidos' => 0, 'erros' => 0, 'pendentes' => 0]);
            }
''')

sub(svc,
    r'''    /**
     * Documentos "enviados" sem estado final há demasiado tempo não ficam
''',
    r'''    /**
     * Pedidos já "a aguardar intervenção" não se consultam ao ritmo de um pedido recente:
     * abrandam para `agt.consulta_intervalo_intervencao` (1 h por omissão).
     */
    protected function consultaDevida(AgtSubmissao $submissao, int $intervalo): bool
    {
        if ($submissao->estado !== AgtSubmissao::AGUARDA_INTERVENCAO || $submissao->ultima_consulta_em === null) {
            return true;
        }

        $espera = max($intervalo, (int) config('faturacao.agt.consulta_intervalo_intervencao', 3600));

        return $submissao->ultima_consulta_em->lte(now()->subSeconds($espera));
    }

    /**
     * Documentos "enviados" sem estado final há demasiado tempo não ficam
''')

# F5-12: depois de a AGT aceitar o pedido, o lote muda de estado por inteiro ou não muda.
sub(svc,
    r'''        if ($requestId !== '') {
            foreach ($enviar as $submissao) {
                $submissao->update([
                    'estado' => AgtSubmissao::ENVIADO,
                    'submission_uuid' => $uuid,
                    'request_id' => $requestId,
                    'tentativas' => $submissao->tentativas + 1,
                    'enviado_em' => now(),
                    'erros' => null,
                ]);
                $res['enviados']++;
            }

            return $res;
        }
''',
    r'''        if ($requestId !== '') {
            // A AGT já aceitou o pedido: o lote inteiro passa a "enviado" ou nenhum documento muda
            // (sem meio-estados se a base de dados falhar a meio).
            DB::transaction(function () use ($enviar, $uuid, $requestId): void {
                foreach ($enviar as $submissao) {
                    $submissao->update([
                        'estado' => AgtSubmissao::ENVIADO,
                        'submission_uuid' => $uuid,
                        'request_id' => $requestId,
                        'tentativas' => $submissao->tentativas + 1,
                        'enviado_em' => now(),
                        'erros' => null,
                    ]);
                }
            });

            $res['enviados'] += count($enviar);

            return $res;
        }
''')

# ---------------------------------------------------------------- Comando de sincronização
cmd = MOD + "app/Console/SincronizarAgtCommand.php"

sub(cmd,
    r'''use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Services\AgtSubmissaoService;
''',
    r'''use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Services\AgtSubmissaoService;
''')

sub(cmd,
    r'''} catch (AgtException $e) {''',
    r'''} catch (AgtException|AssinaturaFiscalException $e) {''')

# ---------------------------------------------------------------- AgtSerieService
sub(MOD + "app/Services/AgtSerieService.php",
    r'''        $quantidade = $this->numeroDe($resultado['authorizedQuantity'] ?? null, obrigatorio: false) ?? max(0, $ultimo - $primeiro + 1);
''',
    r'''        $quantidade = $this->numeroDe($resultado['authorizedQuantity'] ?? null, obrigatorio: false) ?? max(0, $ultimo - $primeiro + 1);

        if ($ultimo < $primeiro) {
            throw AgtException::configuracaoInvalida("a AGT devolveu um intervalo de numeração incoerente para a série {$codigo} ({$primeiro} a {$ultimo}).");
        }
''')

# ---------------------------------------------------------------- Agendador (F5-15)
con = "routes/console.php"

sub(con,
    r'''use Illuminate\Support\Facades\Artisan;
''',
    r'''use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
''')

sub(con,
    r'''Schedule::command('faturacao:agt-sincronizar')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('faturacao.agt.ativo'));
''',
    r'''Schedule::command('faturacao:agt-sincronizar')
    ->everyMinute()
    ->withoutOverlapping(10) // um processo morto não pode bloquear a sincronização durante 24 h
    ->onOneServer()
    ->when(fn () => (bool) config('faturacao.agt.ativo'))
    ->onFailure(fn () => Log::error('faturacao:agt-sincronizar terminou com erro; ver os logs da AGT.'));
''')

# ---------------------------------------------------------------- GerarSafTJob (F5-13)
job = MOD + "app/Jobs/GerarSafTJob.php"

sub(job,
    r'''use Illuminate\Support\Facades\Log;
''',
    r'''use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
''')

sub(job,
    r'''        $errosRegras = $regras->validar($this->empresa, $inicio, $fim);
''',
    r'''        // Pré-validação e geração leem o MESMO instantâneo da base de dados (REPEATABLE READ no MySQL):
        // um documento emitido entre as duas leituras não pode entrar no ficheiro sem ter sido validado.
        [$errosRegras, $xml] = DB::transaction(function () use ($regras, $safTExportService, $inicio, $fim): array {
            $erros = $regras->validar($this->empresa, $inicio, $fim);

            return [$erros, $erros === [] ? $safTExportService->gerar($this->empresa, $inicio, $fim) : null];
        });
''')

sub(job,
    r'''        $xml = $safTExportService->gerar($this->empresa, $inicio, $fim);

''',
    '')

# ---------------------------------------------------------------- AgtCorrecaoService (F5-14)
sub(MOD + "app/Services/AgtCorrecaoService.php",
    r'''            $linhas = $original->linhas->map(fn ($linha) => [
                'produto_id' => in_array($linha->produto_id, $produtosAtivos, true) ? $linha->produto_id : null,
                'descricao' => $linha->descricao,
                'quantidade' => $linha->quantidade,
                'preco_unitario' => $linha->preco_unitario,
                'taxa_iva' => $linha->taxa_iva,
                'motivo_isencao' => $linha->motivo_isencao,
            ])->all();
''',
    r'''            $linhas = $original->linhas->map(function ($linha) use ($produtosAtivos) {
                $item = [
                    'produto_id' => in_array($linha->produto_id, $produtosAtivos, true) ? $linha->produto_id : null,
                    'descricao' => $linha->descricao,
                    'quantidade' => $linha->quantidade,
                    'preco_unitario' => $linha->preco_unitario,
                    'taxa_iva' => $linha->taxa_iva,
                    'motivo_isencao' => $linha->motivo_isencao,
                ];

                // A correção não pode perder o tipo de operação nem o preço base (desconto) da linha rejeitada.
                foreach (['preco_unitario_base', 'operation_type'] as $campo) {
                    if (($linha->{$campo} ?? null) !== null) {
                        $item[$campo] = $linha->{$campo};
                    }
                }

                return $item;
            })->all();
''')

# ---------------------------------------------------------------- Config
sub(MOD + "config/config.php",
    r'''        'consulta_intervalo' => (int) env('FATURACAO_AGT_CONSULTA_INTERVALO', 60), // segundos entre consultas de estado
''',
    r'''        'consulta_intervalo' => (int) env('FATURACAO_AGT_CONSULTA_INTERVALO', 60), // segundos entre consultas de estado
        'consulta_intervalo_intervencao' => (int) env('FATURACAO_AGT_CONSULTA_INTERVALO_INTERVENCAO', 3600), // segundos entre consultas de pedidos "a aguardar intervenção"
''')
PY

echo "==> 3/6 Testes novos e documentação"
cat > Modules/Faturacao/tests/Feature/AgtRobustezFase5Test.php <<'PHP'
<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AgtAssinaturaService;
use Modules\Faturacao\Services\AgtCorrecaoService;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\AgtSubmissaoService;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Tests\TestCase;

/** Fase 5 (auditoria): robustez da comunicação com a AGT. Não prova conformidade fiscal. */
class AgtRobustezFase5Test extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $dirChaves;

    /** @var array{0: string, 1: string} */
    protected array $intervaloSerie = ['1', '1000'];

    protected function setUp(): void
    {
        parent::setUp();

        $privadaEmpresa = $this->gerarChavePrivada();
        $privadaSoftware = $this->gerarChavePrivada();

        $this->dirChaves = sys_get_temp_dir().'/agt-f5-chaves-'.uniqid();
        mkdir($this->dirChaves);
        copy($privadaEmpresa, $this->dirChaves.'/5001636863.pem');
        $this->beforeApplicationDestroyed(function () {
            @unlink($this->dirChaves.'/5001636863.pem');
            @rmdir($this->dirChaves);
        });

        config([
            'faturacao.chave_privada_path' => $privadaEmpresa,
            'faturacao.chaves_dir' => $this->dirChaves,
            'faturacao.agt.ativo' => true,
            'faturacao.agt.ambiente' => 'homologacao',
            'faturacao.agt.utilizador' => 'produtor',
            'faturacao.agt.palavra_passe' => 's3nh@',
            'faturacao.agt.software.chave_privada_path' => $privadaSoftware,
            'faturacao.agt.software.signature_version' => 1,
            'faturacao.saft_numero_certificado' => 'C_134',
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        Http::fake([
            '*solicitarSerie' => fn (Request $r) => Http::response([
                'resultCode' => 1,
                'errorList' => [''],
                'seriesFEResult' => [
                    'seriesCode' => $r['documentType'].'6325S2C',
                    'authorizedQuantity' => '1000',
                    'firstDocumentNo' => $this->intervaloSerie[0],
                    'lastDocumentNo' => $this->intervaloSerie[1],
                ],
            ], 200),
            '*registarFactura' => Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
            '*obterEstado' => Http::response(['resultCode' => '8'], 200),
        ]);
    }

    protected function gerarChavePrivada(): string
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);

        $caminho = sys_get_temp_dir().'/agt-f5-priv-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        return $caminho;
    }

    protected function emitirFatura(): Fatura
    {
        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);

        $servico = app(FaturaService::class);

        return $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14],
        ]));
    }

    /** @return Collection<int, Request> */
    protected function pedidosPara(string $servico): Collection
    {
        return collect(Http::recorded())
            ->map(fn ($par) => $par[0])
            ->filter(fn (Request $pedido) => str_contains($pedido->url(), $servico))
            ->values();
    }

    public function test_chave_do_emissor_em_falta_nao_aborta_a_consulta_de_estados(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        unlink($this->dirChaves.'/5001636863.pem');
        app()->forgetInstance(AssinaturaFiscalService::class);
        app()->forgetInstance(AgtAssinaturaService::class);
        app()->forgetInstance(AgtSubmissaoService::class);

        $res = app(AgtSubmissaoService::class)->consultarEstados();

        $this->assertSame(1, $res['pendentes']);
        $this->assertSame(AgtSubmissao::ENVIADO, AgtSubmissao::query()->firstOrFail()->estado);
        $this->assertCount(0, $this->pedidosPara('obterEstado'));
    }

    public function test_pedidos_a_aguardar_intervencao_sao_consultados_com_intervalo_maior(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $submissao->update([
            'estado' => AgtSubmissao::AGUARDA_INTERVENCAO,
            'erros' => [['codigo' => 'SEM_RESPOSTA', 'descricao' => 'teste']],
            'ultima_consulta_em' => now()->subMinutes(5),
        ]);

        app(AgtSubmissaoService::class)->consultarEstados();
        $this->assertCount(0, $this->pedidosPara('obterEstado'), 'Cinco minutos depois ainda não é altura de consultar.');

        $submissao->update(['ultima_consulta_em' => now()->subHours(2)]);

        app(AgtSubmissaoService::class)->consultarEstados();
        $this->assertCount(1, $this->pedidosPara('obterEstado'));
    }

    public function test_correcao_de_fatura_rejeitada_preserva_tipo_de_operacao_e_preco_base(): void
    {
        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);

        $servico = app(FaturaService::class);
        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [[
            'descricao' => 'Serviço com desconto',
            'quantidade' => 2,
            'preco_unitario_base' => '1000.00',
            'preco_unitario' => '900.00',
            'taxa_iva' => 14,
            'operation_type' => 'TB',
        ]]));

        $submissao = AgtSubmissao::query()->where('documento_id', $fatura->id)->firstOrFail();
        $submissao->update(['estado' => AgtSubmissao::REJEITADO, 'erros' => [['codigo' => 'E16', 'descricao' => 'teste']]]);

        $rascunho = app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($submissao);
        $linha = $rascunho->linhas->first();

        $this->assertSame('TB', $linha->operation_type);
        $this->assertEqualsWithDelta(1000.0, (float) $linha->preco_unitario_base, 0.001);
        $this->assertEqualsWithDelta(900.0, (float) $linha->preco_unitario, 0.001);
    }

    public function test_serie_com_intervalo_incoerente_e_recusada(): void
    {
        $this->intervaloSerie = ['5', '2'];

        $this->expectException(AgtException::class);

        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);
    }
}
PHP

cat >> docs/homologacao-agt.md <<'MD'

## 5. Fase 5 — auditoria e correções de robustez

- Relatório: `docs/auditoria-fase5-saft-agt.md` (achados F5-01 a F5-21, com estado por achado).
- As correções da Fase 5 melhoram a **robustez** da comunicação e da geração do SAF-T; **não fecham** os pontos H1–H9
  e não autorizam a afirmação «homologado» ou «conforme à AGT».
MD

cat > docs/auditoria-fase5-saft-agt.md <<'MD'
# Auditoria da Fase 5 — SAF-T Angola e comunicação com a AGT

**Base:** `main` @ `bfde8eb` (PR #36). **Método:** leitura estática de `AgtClient`, `AgtSubmissaoService`, `AgtSerieService`,
`AgtCorrecaoService`, `AgtDocumentoBuilder`, `AgtAssinaturaService`, `AssinaturaFiscalService`, `SafTExportService`,
`SafTPreValidacao`, `SafTValidadorXsd`, `GerarSafTJob`, `ReciboService`, config, migration `agt_submissoes`, agendador e
`docs/homologacao-agt.md`. Nada foi executado contra a AGT. **Este documento não afirma conformidade fiscal.**

Estados: **CORRIGIDO** (neste PR) · **DECISÃO** (precisa de escolha de desenho) · **EVIDÊNCIA** (só XSD/AGT/certificação resolvem).

| ID | Prio | Achado | Ficheiros | Estado |
|---|---|---|---|---|
| F5-01 | P0 | SAF-T provisório: sem XSD oficial, namespace por confirmar, `Payments` provisório (H1/H2) | `SafTExportService`, `SafTValidadorXsd` | EVIDÊNCIA |
| F5-02 | P0 | O campo `Hash` recebe o JWS completo; pode exceder o limite do esquema anterior | `SafTExportService` | EVIDÊNCIA |
| F5-03 | P0 | Elementos que o exportador não emite e que podem ser obrigatórios no XSD (a confirmar): `TaxExemptionReason` (a `mencao` já existe na config), estrutura de `CompanyAddress`, `ProductType`/`ProductGroup`, nome do campo do nº de certificação | `SafTExportService` | EVIDÊNCIA |
| F5-04 | P0 | Abatimentos de adiantamentos sem representação fiscal (H3); a recusa em produção é a salvaguarda correta | `SafTPreValidacao` | EVIDÊNCIA |
| F5-05 | P0 | Com a AGT ativa, `ReciboService::emitir` (RC) lança `DomainException` e o builder só aceita AR/RG: o caminho padrão de recibos fica fechado; só RG com fatura de origem passa | `ReciboService`, `AgtDocumentoBuilder` | DECISÃO |
| F5-06 | P1 | `falhaTransitoria` soma 1 tentativa por execução (minuto a minuto) e ao fim de `max_tentativas` marca ERRO: ~5 min de indisponibilidade obrigam a reenvio manual de tudo. Pede backoff por documento (coluna `proxima_tentativa_em`) | `AgtSubmissaoService` | DECISÃO |
| F5-07 | P1 | Contingência: a série aceita o indicador `C`, mas não há fluxo de emissão em contingência nem reconciliação posterior | `AgtSerieService` | DECISÃO |
| F5-08 | P1 | `obterEstado` com HTTP 400, E95 ou `resultCode` 9 marca ERRO **com** requestID: fica fora de `aguardamIntervencao()`, de `reenviar()` e da consulta — sem caminho operacional | `AgtSubmissaoService` | DECISÃO |
| F5-09 | P1 | Chave de emissor em falta numa empresa abortava a consulta de estados de todas (`AssinaturaFiscalException` não tratada); o comando terminava com stack trace | `AgtSubmissaoService`, `SincronizarAgtCommand` | CORRIGIDO |
| F5-10 | P1 | Critérios de ambiente inconsistentes: SAF-T, abatimentos e isenções usam `app()->isProduction()`; a AGT usa `agt.ambiente` | `SafTExportService`, `AgtClient` | DECISÃO |
| F5-11 | P2 | Pedidos «a aguardar intervenção» consultados de 60 em 60 s indefinidamente | `AgtSubmissaoService` | CORRIGIDO (1 h, configurável) |
| F5-12 | P2 | Depois de a AGT aceitar, o lote era atualizado documento a documento, sem transação | `AgtSubmissaoService` | CORRIGIDO |
| F5-13 | P2 | Pré-validação e geração do SAF-T liam a BD em momentos distintos | `GerarSafTJob` | CORRIGIDO (um só instantâneo) |
| F5-14 | P2 | A correção de fatura rejeitada perdia `operation_type` e `preco_unitario_base` | `AgtCorrecaoService` | CORRIGIDO |
| F5-15 | P2 | Agendador: `withoutOverlapping()` com mutex de 24 h, sem `onOneServer`, sem alerta de falha | `routes/console.php` | CORRIGIDO |
| F5-16 | P2 | Série AGT: intervalo devolvido não era validado (CORRIGIDO); pedidos concorrentes sem idempotência e extensão que sobrescreve `quantidade_autorizada` (a confirmar) | `AgtSerieService` | CORRIGIDO + DECISÃO |
| F5-17 | P2 | Um único lote (30) por empresa por execução: no máximo 30 documentos/min/empresa | `AgtSubmissaoService` | DECISÃO |
| F5-18 | P2 | `MasterFiles` do SAF-T lê Cliente/Produto atuais e não o snapshot congelado nos documentos | `SafTExportService` | DECISÃO |
| F5-19 | P2 | SAF-T construído em DOM em memória e validado sobre a string completa; sem streaming | `SafTExportService`, `SafTValidadorXsd` | DECISÃO |
| F5-20 | P3 | `request_id` é `varchar(15)`: se a AGT devolver mais, a escrita falha depois de aceite e o reenvio repete-se (E09) | migration `agt_submissoes` | EVIDÊNCIA |
| F5-21 | P3 | `GerarSafTJob`: rotação para `historico/` e escrita do novo ficheiro não são atómicas; gerações no mesmo segundo colidem | `GerarSafTJob` | DECISÃO |

H7 (ensaio completo em homologação da AGT: séries, submissão, rejeições, correções) continua em aberto e não é substituído por nenhum teste interno.

## O que este PR altera
F5-09, F5-11, F5-12, F5-13, F5-14, F5-15 e a validação do intervalo de F5-16. Novo teste: `AgtRobustezFase5Test`.
Nova chave de configuração: `faturacao.agt.consulta_intervalo_intervencao` (`FATURACAO_AGT_CONSULTA_INTERVALO_INTERVENCAO`, 3600 s).

## O que fica fora e porquê
Os itens DECISÃO mudam comportamento fiscal ou exigem migration; os itens EVIDÊNCIA dependem do XSD oficial, da AGT ou da certificação.
Não se implementam funcionalidades novas antes de fechar todas as fases.
MD

echo "==> 4/6 Formatação (Pint)"
if [ -x vendor/bin/pint ]; then vendor/bin/pint --dirty; else echo "  (pint não instalado — a saltar)"; fi

echo "==> 5/6 Testes do módulo Faturação"
if ! php artisan test --filter=Faturacao; then
  echo
  echo "TESTES FALHARAM — nada foi commitado. Envia-me o output acima."
  echo "Para repor o ramo:  git reset --hard && git clean -fd -e $SCRIPT"
  exit 1
fi

echo "==> 6/6 Commit, push e PR"
git add -A -- . ":(exclude)$SCRIPT"
git commit -m "fix(faturacao): fase 5 — robustez da comunicação AGT e da geração do SAF-T" \
  -m "Corrige F5-09, F5-11..F5-15 e a validação de intervalo de F5-16 (ver docs/auditoria-fase5-saft-agt.md). Não altera H1–H9 nem declara conformidade fiscal."
git push -u origin "$RAMO"

if command -v gh >/dev/null; then
  gh pr create --base main --head "$RAMO" \
    --title "Fase 5 — SAF-T Angola e comunicação AGT: auditoria e correções de robustez" \
    --body-file docs/auditoria-fase5-saft-agt.md
else
  echo "gh não encontrado: abre o PR do ramo $RAMO para main no GitHub."
fi

rm -f -- "$SCRIPT"
echo "Concluído."
