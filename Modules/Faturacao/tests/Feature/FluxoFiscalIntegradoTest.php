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
