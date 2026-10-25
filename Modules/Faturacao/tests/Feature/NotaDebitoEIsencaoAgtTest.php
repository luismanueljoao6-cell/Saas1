<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\AgtDocumentoBuilder;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\AgtSubmissaoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Support\Jws;
use Tests\TestCase;

/**
 * Como a nota de débito (sem imposto) e as linhas isentas seguem para a AGT:
 * taxType NS / IVA+ISE com taxExemptionCode — e só com a tabela de motivos confirmada.
 */
class NotaDebitoEIsencaoAgtTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir().'/agt-chaves-'.uniqid();
        mkdir($dir);
        $privadaEmpresa = $this->gerarChave($dir.'/5001636863.pem');
        $privadaSoftware = $this->gerarChave(sys_get_temp_dir().'/agt-sw-'.uniqid().'.pem');
        $this->beforeApplicationDestroyed(function () use ($dir, $privadaSoftware) {
            @unlink("{$dir}/5001636863.pem");
            @rmdir($dir);
            @unlink($privadaSoftware);
        });

        config([
            'faturacao.chave_privada_path' => $privadaEmpresa,
            'faturacao.chaves_dir' => $dir,
            'faturacao.agt.ativo' => true,
            'faturacao.agt.ambiente' => 'homologacao',
            'faturacao.agt.utilizador' => 'produtor',
            'faturacao.agt.palavra_passe' => 's3nh@',
            'faturacao.agt.software.chave_privada_path' => $privadaSoftware,
            'faturacao.agt.software.signature_version' => null,
            'faturacao.saft_numero_certificado' => 'C_134',
            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
            'faturacao.motivos_isencao_confirmados' => true,
            'faturacao.agt.nd_codigo_nao_sujeicao' => 'M02',
        ]);

        Http::fake([
            '*solicitarSerie' => fn (Request $r) => Http::response([
                'resultCode' => 1,
                'errorList' => [''],
                'seriesFEResult' => [
                    'seriesCode' => $r['documentType'].'6325S2C',
                    'authorizedQuantity' => '1000',
                    'firstDocumentNo' => '1',
                    'lastDocumentNo' => '1000',
                ],
            ], 200),
            '*registarFactura' => Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        foreach (['FT', 'NC', 'ND'] as $tipo) {
            app(AgtSerieService::class)->solicitar($this->empresa, $tipo, now()->year);
        }
    }

    protected function gerarChave(string $caminho): string
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($caminho, $pem);

        return $caminho;
    }

    /** @return array<int, array<string, mixed>> */
    protected function linhaIsenta(string $motivo): array
    {
        return [['descricao' => 'Livro', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0, 'motivo_isencao' => $motivo]];
    }

    /** @return array<string, mixed> */
    protected function construido(object $documento): array
    {
        return json_decode(Jws::canonico(app(AgtDocumentoBuilder::class)->construir($documento)), true);
    }

    protected function notaDeDebito(): object
    {
        $notas = app(NotaCreditoDebitoService::class);

        return $notas->emitir($notas->criarRascunho($this->empresa->id, $this->cliente->id, 'debito', 'Repasse de despesas', [
            ['descricao' => 'Repasse de despesas', 'quantidade' => 1, 'preco_unitario' => '250.00', 'taxa_iva' => 0],
        ]));
    }

    public function test_nota_de_debito_segue_para_a_agt_como_nao_sujeita_com_codigo(): void
    {
        $doc = $this->construido($this->notaDeDebito());

        $this->assertSame('ND', $doc['documentType']);
        $this->assertSame(
            ['taxType' => 'NS', 'taxCountryRegion' => 'AO', 'taxPercentage' => 0, 'taxContribution' => 0, 'taxExemptionCode' => 'M02'],
            $doc['lines'][0]['taxes'][0],
        );
        $this->assertSame(250, $doc['lines'][0]['creditAmount']);
        $this->assertSame(['taxPayable' => 0, 'netTotal' => 250, 'grossTotal' => 250], $doc['documentTotals']);
    }

    public function test_nota_de_debito_sem_codigo_configurado_segue_sem_bloco_de_impostos(): void
    {
        config(['faturacao.agt.nd_codigo_nao_sujeicao' => null, 'faturacao.motivos_isencao_confirmados' => false]);

        $doc = $this->construido($this->notaDeDebito());

        $this->assertArrayNotHasKey('taxes', $doc['lines'][0]);
        $this->assertSame(['taxPayable' => 0, 'netTotal' => 250, 'grossTotal' => 250], $doc['documentTotals']);
    }

    public function test_o_envio_da_nota_de_debito_usa_o_formato_configurado(): void
    {
        $this->notaDeDebito();

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(0, $res['erros']);
        $enviado = collect(Http::recorded())
            ->map(fn ($par) => $par[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'registarFactura'))
            ->flatMap(fn (Request $r) => $r->data()['documents'] ?? [])
            ->first(fn (array $d) => $d['documentType'] === 'ND');

        $this->assertSame('NS', $enviado['lines'][0]['taxes'][0]['taxType']);
    }

    public function test_codigo_de_nao_sujeicao_tem_de_ser_do_tipo_ns(): void
    {
        $nota = $this->notaDeDebito();
        config(['faturacao.agt.nd_codigo_nao_sujeicao' => 'M13']); // M13 é uma isenção (ISE), não uma não sujeição

        $this->expectException(AgtException::class);
        $this->expectExceptionMessage('FATURACAO_AGT_ND_CODIGO_NS');

        app(AgtDocumentoBuilder::class)->construir($nota);
    }

    public function test_tabela_de_motivos_por_confirmar_bloqueia_o_envio(): void
    {
        $nota = $this->notaDeDebito();
        config(['faturacao.motivos_isencao_confirmados' => false]);

        $this->expectException(AgtException::class);
        $this->expectExceptionMessage('FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS');

        app(AgtDocumentoBuilder::class)->construir($nota);
    }

    public function test_linha_isenta_segue_como_iva_ise_com_taxexemptioncode(): void
    {
        $faturas = app(FaturaService::class);
        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linhaIsenta('M13')));

        $doc = $this->construido($fatura);

        $this->assertSame(
            ['taxType' => 'IVA', 'taxCountryRegion' => 'AO', 'taxCode' => 'ISE', 'taxPercentage' => 0, 'taxContribution' => 0, 'taxExemptionCode' => 'M13'],
            $doc['lines'][0]['taxes'][0],
        );
        $this->assertSame(['taxPayable' => 0, 'netTotal' => 100, 'grossTotal' => 100], $doc['documentTotals']);
    }

    public function test_linha_nao_sujeita_segue_como_ns_sem_taxcode(): void
    {
        $faturas = app(FaturaService::class);
        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linhaIsenta('M02')));

        $imposto = $this->construido($fatura)['lines'][0]['taxes'][0];

        $this->assertSame('NS', $imposto['taxType']);
        $this->assertArrayNotHasKey('taxCode', $imposto);
        $this->assertSame('M02', $imposto['taxExemptionCode']);
    }

    public function test_linha_com_iva_continua_igual(): void
    {
        $faturas = app(FaturaService::class);
        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        $this->assertSame(
            ['taxType' => 'IVA', 'taxCountryRegion' => 'AO', 'taxCode' => 'NOR', 'taxPercentage' => 14, 'taxContribution' => 140],
            $this->construido($fatura)['lines'][0]['taxes'][0],
        );
    }

    public function test_isencao_e_recusada_logo_na_criacao_enquanto_a_tabela_nao_estiver_confirmada(): void
    {
        config(['faturacao.motivos_isencao_confirmados' => false]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linhaIsenta('M13'));
    }
}
