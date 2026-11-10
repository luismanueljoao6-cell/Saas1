#!/usr/bin/env bash
# PR #33 — Fase 11: testes de integração fiscal ponta a ponta + checklist de homologação.
# Correr na raiz do repositório Saas1, na branch feat/faturacao-fiscal-core.
# Não faz commit. Não altera código de produção: só acrescenta um teste e um documento.
set -euo pipefail

RAMO="$(git rev-parse --abbrev-ref HEAD)"
if [ "$RAMO" != "feat/faturacao-fiscal-core" ]; then
  echo "Branch errada: $RAMO (esperado feat/faturacao-fiscal-core)." >&2
  exit 1
fi

mkdir -p docs

# ---------------------------------------------------------------------------
# 1. Teste de integração: FA -> recibo -> abatimento -> FT -> recibo -> NC -> SAF-T
# ---------------------------------------------------------------------------
cat > Modules/Faturacao/tests/Feature/FluxoFiscalIntegradoTest.php <<'PHP'
<?php

namespace Modules\Faturacao\Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AbatimentoAdiantamentoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Services\SafTExportService;
use Modules\Faturacao\Services\SafTPreValidacao;
use Tests\TestCase;

/**
 * Fase 11 da PR #33 — integração fiscal ponta a ponta.
 *
 * Prova a COERÊNCIA INTERNA entre documentos, saldos e SAF-T gerado. Não prova
 * conformidade com a AGT: isso exige o XSD oficial, a especificação do adiantamento
 * e a certificação (ver docs/homologacao-agt.md).
 */
class FluxoFiscalIntegradoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/fluxo-fiscal-'.uniqid();
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
            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja A', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente A', 'nif' => null]);
    }

    /** FA de 1140.00 (1000 + 14%), emitido e totalmente recebido. */
    protected function adiantamentoRecebido(?Empresa $empresa = null, ?Cliente $cliente = null): Fatura
    {
        $empresa ??= $this->empresa;
        $cliente ??= $this->cliente;

        $faturas = app(FaturaService::class);
        $fa = $faturas->emitir($faturas->criarRascunhoAdiantamento($empresa, $cliente, [
            ['descricao' => 'Adiantamento', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        app(ReciboService::class)->emitir($empresa->id, $cliente->id, '1140.00', 'dinheiro', $fa->id);

        return Fatura::withoutGlobalScopes()->findOrFail($fa->id);
    }

    /** FT de 1140.00 (1000 + 14%), emitida e por pagar. */
    protected function faturaFinal(?Empresa $empresa = null, ?Cliente $cliente = null): Fatura
    {
        $empresa ??= $this->empresa;
        $cliente ??= $this->cliente;

        $faturas = app(FaturaService::class);
        $ft = $faturas->emitir($faturas->criarRascunho($empresa, $cliente, [
            ['descricao' => 'Venda final', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        return Fatura::withoutGlobalScopes()->findOrFail($ft->id);
    }

    protected function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadXML(preg_replace('/ xmlns="[^"]*"/', '', $xml, 1));

        return new DOMXPath($dom);
    }

    protected function saft(Empresa $empresa): DOMXPath
    {
        return $this->xpath(app(SafTExportService::class)->gerar($empresa, now()->startOfYear(), now()->endOfYear()));
    }

    /** @return list<string> */
    protected function valores(DOMXPath $x, string $consulta): array
    {
        $valores = [];
        foreach ($x->query($consulta) as $no) {
            $valores[] = trim($no->nodeValue);
        }
        sort($valores);

        return $valores;
    }

    public function test_ciclo_completo_adiantamento_abatimento_fatura_final_e_saft_reconciliam(): void
    {
        $recibos = app(ReciboService::class);
        $notas = app(NotaCreditoDebitoService::class);

        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();

        // Abate 500.00 do adiantamento na fatura final: o saldo da FT desce para 640.00.
        $abatimento = app(AbatimentoAdiantamentoService::class)
            ->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-ciclo-0001');
        $this->assertSame(50000, (int) $abatimento['valor_centimos']);
        $this->assertSame(64000, (int) $recibos->resumoDaFatura(Fatura::withoutGlobalScopes()->findOrFail($ft->id))['saldo']);

        // O cliente paga o restante e a FT fica liquidada.
        $recibos->emitir($this->empresa->id, $this->cliente->id, '640.00', 'transferencia', $ft->id);
        $this->assertSame(0, (int) $recibos->resumoDaFatura(Fatura::withoutGlobalScopes()->findOrFail($ft->id))['saldo']);

        // Nota de crédito parcial sobre a parte do adiantamento ainda não abatida.
        $nota = $notas->emitir($notas->criarRascunho(
            $this->empresa->id,
            $this->cliente->id,
            'credito',
            'Ajuste',
            [['descricao' => 'Correção', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14]],
            $fa->id,
        ));
        $this->assertSame('emitida', $nota->estado);

        // A coerência interna tem de passar antes de qualquer exportação.
        $this->assertSame([], app(SafTPreValidacao::class)->validar($this->empresa, now()->startOfYear(), now()->endOfYear()));

        $x = $this->saft($this->empresa);

        // Documentos de venda: FA + FT (crédito, valores líquidos) e NC (débito).
        $this->assertSame('3', $x->evaluate('string(//SalesInvoices/NumberOfEntries)'));
        $this->assertSame('2000.00', $x->evaluate('string(//SalesInvoices/TotalCredit)'));
        $this->assertSame('100.00', $x->evaluate('string(//SalesInvoices/TotalDebit)'));

        // Recibos: 1140.00 (FA) + 640.00 (FT), cada um ligado ao documento de origem.
        $this->assertSame('2', $x->evaluate('string(//Payments/NumberOfEntries)'));
        $this->assertSame('1780.00', $x->evaluate('string(//Payments/TotalCredit)'));

        $esperados = [$fa->numero_documento, $ft->numero_documento];
        sort($esperados);
        $this->assertSame($esperados, $this->valores($x, '//Payment/Line/SourceDocumentID/OriginatingON'));

        // Os totais do ficheiro batem com a soma dos próprios documentos exportados.
        $somaGross = 0;
        foreach ($x->query('//Payment/DocumentTotals/GrossTotal') as $no) {
            $somaGross += (int) round(((float) $no->nodeValue) * 100);
        }
        $this->assertSame(178000, $somaGross);
    }

    public function test_abatimento_repetido_com_a_mesma_chave_devolve_o_original_sem_duplicar(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        $servico = app(AbatimentoAdiantamentoService::class);

        $primeiro = $servico->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-idem-0001');
        $segundo = $servico->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-idem-0001');

        $this->assertSame((int) $primeiro['id'], (int) $segundo['id']);
        $this->assertSame(1, DB::table('adiantamento_abatimentos')->where('empresa_id', $this->empresa->id)->count());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Chave de idempotência reutilizada com dados diferentes.');

        $servico->abater($this->empresa->id, $fa->id, $ft->id, '400.00', 'chave-idem-0001');
    }

    public function test_saft_e_abatimentos_ficam_isolados_por_empresa(): void
    {
        $empresaB = Empresa::create(['nome_comercial' => 'Loja B', 'nif' => '5001636864']);
        $clienteB = Cliente::create(['empresa_id' => $empresaB->id, 'nome' => 'Cliente B', 'nif' => null]);

        $fa = $this->adiantamentoRecebido();
        $this->faturaFinal($empresaB, $clienteB);
        $this->faturaFinal($empresaB, $clienteB);

        $a = $this->saft($this->empresa);
        $b = $this->saft($empresaB);

        // A empresa A tem só o FA; a B tem só as suas duas FT. Nada atravessa a fronteira.
        $this->assertSame('5001636863', $a->evaluate('string(//Header/TaxRegistrationNumber)'));
        $this->assertSame('1', $a->evaluate('string(//SalesInvoices/NumberOfEntries)'));
        $this->assertSame('1', $a->evaluate('string(//Payments/NumberOfEntries)'));

        $this->assertSame('5001636864', $b->evaluate('string(//Header/TaxRegistrationNumber)'));
        $this->assertSame('2', $b->evaluate('string(//SalesInvoices/NumberOfEntries)'));
        $this->assertSame('0', $b->evaluate('string(//Payments/NumberOfEntries)'));

        // Um abatimento nunca liga documentos de empresas diferentes.
        $ftB = Fatura::withoutGlobalScopes()->where('empresa_id', $empresaB->id)->firstOrFail();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('As duas faturas têm de existir nesta empresa.');

        app(AbatimentoAdiantamentoService::class)->abater($this->empresa->id, $fa->id, $ftB->id, '100.00', 'chave-cross-0001');
    }

    public function test_representacao_fiscal_do_abatimento_aguarda_a_especificacao_da_agt(): void
    {
        $this->markTestIncomplete(
            'Em aberto (auditoria PR #33, ponto 2.1): o abatimento de adiantamentos é hoje contabilidade '.
            'interna e não gera documento fiscal nem entra na comunicação AGT/SAF-T. A representação '.
            'correta (FA, abatimento na FT, SAF-T) depende da especificação oficial da AGT. '.
            'Quando estiver definida, este teste passa a reconciliar documentos e valores.'
        );
    }
}
PHP

# ---------------------------------------------------------------------------
# 2. Checklist de homologação: o que está provado por testes e o que exige evidência externa
# ---------------------------------------------------------------------------
cat > docs/homologacao-agt.md <<'MD'
# Homologação fiscal AGT — estado e evidências em falta

Este documento separa **o que os testes automáticos provam** do que **só a AGT, o XSD
oficial ou uma certificação podem provar**. Testes internos verdes (CI, PR #33) **não são
homologação fiscal** e não devem ser apresentados como tal.

## 1. Provado por testes automáticos (coerência interna)

| Garantia | Onde |
|---|---|
| Valores monetários sem `float` (`Dinheiro`) | `Support/Dinheiro` e testes de linhas |
| Numeração sem lacunas e cadeia de hash por série (FT, NC/ND, recibos) | `SafTPreValidacao`, `CadeiaFiscalTest`, `SafTPreValidacaoTest`, `SafTRecibosTest` |
| Totais do documento = soma das linhas; IVA recalculado | `SafTPreValidacao::verificarDocumento` |
| Recibos exportados em `Payments`, ligados à fatura de origem, sem exceder o total | `SafTRecibosTest` |
| Abatimento de adiantamento idempotente, concorrente e isolado por empresa | `AbatimentoAdiantamentoService`, `AdiantamentoNotaCreditoTest`, `FluxoFiscalIntegradoTest` |
| NC sobre FA nunca invade a parte já abatida; anulação total recusada se houver abatimentos | `AdiantamentoNotaCreditoTest` |
| Ciclo FA → recibo → abatimento → FT → recibo → NC → SAF-T reconcilia (saldos e totais) | `FluxoFiscalIntegradoTest` |
| SAF-T e abatimentos isolados por empresa | `FluxoFiscalIntegradoTest` |
| Exportação SAF-T bloqueada em produção sem número de certificação | `SafTExportService::exportacaoBloqueada` |
| Imutabilidade (triggers) e FK `RESTRICT` nos abatimentos | migration `2026_11_10_000024` |

## 2. Em aberto — exige evidência externa

| # | Ponto | O que falta | Configuração / código afetado |
|---|---|---|---|
| H1 | Estrutura do SAF-T (AO) | Validar um ficheiro real contra o **XSD oficial** da AGT (inclui `Payments`, hoje provisório) | `faturacao.saft_xsd_path`, `SafTValidadorXsd`, `GerarSafTJob` |
| H2 | Namespace do ficheiro | Confirmar o `targetNamespace` oficial | `faturacao.saft_namespace` |
| H3 | Adiantamentos (FA) e abatimentos | Definir como o abatimento aparece na FT, na comunicação AGT e no SAF-T | `AbatimentoAdiantamentoService` (teste `…aguarda_a_especificacao_da_agt`) |
| H4 | Motivos de isenção | Confirmar a tabela contra o anexo oficial | `faturacao.motivos_isencao`, `FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS` |
| H5 | Códigos de taxa SAF-T | Confirmar os códigos por taxa | `faturacao.agt.codigos_taxa` |
| H6 | Certificação do software | Obter e configurar o número de certificado | `faturacao.saft_numero_certificado` |
| H7 | Comunicação com a AGT | Ensaio completo no ambiente de **homologação** da AGT (séries, submissão, correção de rejeitados) | `faturacao.agt.*`, `AgtSubmissaoService`, `AgtSerieService` |
| H8 | Base de dados real | Correr a suite e as migrations (instalação limpa, upgrade, rollback) em **MySQL** com dados de teste | CI MySQL; `adiantamento_abatimentos` (triggers) |
| H9 | Integridade entre empresas na BD | Hoje garantida pelo serviço; avaliar chave composta (empresa, fatura) nas FKs dos abatimentos | `adiantamento_abatimentos` |

## 3. Regra de comunicação

- Nunca afirmar «homologado» ou «conforme à AGT» enquanto H1, H3, H6 e H7 estiverem abertos.
- Cada ponto fechado regista aqui a evidência (data, ficheiro XSD/versão, resposta da AGT) e o commit.
MD

# ---------------------------------------------------------------------------
# 3. Sintaxe, Pint e testes
# ---------------------------------------------------------------------------
php -l Modules/Faturacao/tests/Feature/FluxoFiscalIntegradoTest.php
vendor/bin/pint Modules/Faturacao/tests/Feature/FluxoFiscalIntegradoTest.php

php artisan test --filter='FluxoFiscalIntegradoTest|SafTRecibosTest|AdiantamentoNotaCreditoTest|SafTPreValidacaoTest'

echo
git status --short
echo
echo "Se os testes estiverem verdes:"
echo "  git add Modules/Faturacao/tests docs/homologacao-agt.md && git commit -m 'test(faturacao): integração fiscal ponta a ponta e checklist de homologação AGT (fase 11 da PR #33)' && git push"
