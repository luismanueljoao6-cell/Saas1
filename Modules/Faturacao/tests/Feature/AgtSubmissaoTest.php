<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\AgtSubmissaoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Jws;
use Modules\Faturacao\Support\NumeroJson;
use Tests\TestCase;

class AgtSubmissaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    /** @var array<string, mixed> */
    protected array $respostaRegistar = ['requestID' => '202500000010689', 'errorList' => []];

    /** @var array<string, mixed> */
    protected array $respostaEstado = ['resultCode' => '8'];

    protected bool $falharRede = false;

    protected function setUp(): void
    {
        parent::setUp();

        [$privadaEmpresa] = $this->gerarPar();
        [$privadaSoftware] = $this->gerarPar();

        config([
            'faturacao.chave_privada_path' => $privadaEmpresa,
            'faturacao.chaves_dir' => sys_get_temp_dir().'/agt-sem-chaves-'.uniqid(),
            'faturacao.agt.ativo' => true,
            'faturacao.agt.ambiente' => 'homologacao',
            'faturacao.agt.utilizador' => 'produtor',
            'faturacao.agt.palavra_passe' => 's3nh@',
            'faturacao.agt.software.chave_privada_path' => $privadaSoftware,
            'faturacao.agt.software.signature_version' => null,
            'faturacao.saft_numero_certificado' => 'C_134',
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        $this->fingirAgt();
    }

    /** @return array{0: string} */
    protected function gerarPar(): array
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/agt-priv-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);
        $this->beforeApplicationDestroyed(fn () => @unlink($privada));

        return [$privada];
    }

    /**
     * Simula a AGT uma só vez (um novo Http::fake() apagaria os pedidos
     * gravados e as respostas antigas ganhariam às novas); as respostas lidas
     * aqui vêm das propriedades, que cada teste pode alterar.
     */
    protected function fingirAgt(): void
    {
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
            '*registarFactura' => fn () => $this->falharRede
                ? throw new ConnectionException('timeout')
                : Http::response($this->respostaRegistar, isset($this->respostaRegistar['requestID']) ? 200 : 400),
            '*obterEstado' => fn () => Http::response($this->respostaEstado, 200),
        ]);
    }

    protected function emitirFatura(): Fatura
    {
        foreach (['FT', 'RC'] as $tipo) {
            app(AgtSerieService::class)->solicitar($this->empresa, $tipo, now()->year);
        }

        $servico = app(FaturaService::class);
        $linhas = [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]];

        return $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, $linhas));
    }

    protected function pedidoPara(string $servico): Request
    {
        return collect(Http::recorded())->map(fn ($par) => $par[0])->first(fn (Request $r) => str_contains($r->url(), $servico));
    }

    public function test_emitir_com_agt_ativa_cria_submissao_pendente(): void
    {
        $fatura = $this->emitirFatura();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('pendente', $submissao->estado);
        $this->assertSame('FT FT6325S2C/1', $submissao->documento_no);
        $this->assertSame($fatura->id, $submissao->documento_id);
    }

    public function test_enviar_pendentes_monta_o_documento_segundo_a_especificacao(): void
    {
        $fatura = $this->emitirFatura();

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(1, $res['enviados']);
        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('enviado', $submissao->estado);
        $this->assertSame('202500000010689', $submissao->request_id);

        $dados = $this->pedidoPara('registarFactura')->data();
        $this->assertSame('2.0', $dados['schemaVersion']);
        $this->assertSame('5001636863', $dados['taxRegistrationNumber']);
        $this->assertSame(1, $dados['numberOfEntries']);
        $this->assertSame('C_134', $dados['softwareInfo']['softwareInfoDetail']['softwareValidationNumber']);

        $documento = $dados['documents'][0];
        $this->assertSame('FT FT6325S2C/1', $documento['documentNo']);
        $this->assertSame('N', $documento['documentStatus']);
        $this->assertSame($fatura->hash, $documento['jwsDocumentSignature']);
        $this->assertSame('FT', $documento['documentType']);
        $this->assertSame('999999999', $documento['customerTaxID']);
        $this->assertSame('AO', $documento['customerCountry']);
        $this->assertSame(['taxPayable' => 140, 'netTotal' => 1000, 'grossTotal' => 1140], $documento['documentTotals']);

        $linha = $documento['lines'][0];
        $this->assertSame(1, $linha['lineNumber']);
        $this->assertSame('SG', $linha['operationType']);
        $this->assertSame(1, $linha['quantity']);
        $this->assertSame(1000, $linha['unitPriceBase']);
        $this->assertSame(1000, $linha['creditAmount']);
        $this->assertArrayNotHasKey('debitAmount', $linha);
        $this->assertSame(0, $linha['settlementAmount']);
        $this->assertSame(
            ['taxType' => 'IVA', 'taxCountryRegion' => 'AO', 'taxCode' => 'NOR', 'taxPercentage' => 14, 'taxContribution' => 140],
            $linha['taxes'][0],
        );

        // O que se envia é exactamente o objecto que foi assinado.
        $assinado = json_decode(Jws::payload($documento['jwsDocumentSignature']), true);
        $this->assertSame($assinado['documentTotals'], $documento['documentTotals']);
        $this->assertSame($assinado['documentNo'], $documento['documentNo']);
    }

    public function test_recibo_vai_com_payment_receipt_e_sem_linhas(): void
    {
        $fatura = $this->emitirFatura();
        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '500.00', 'dinheiro', $fatura->id);

        $this->assertSame(2, app(AgtSubmissaoService::class)->enviarPendentes()['enviados']);

        $documentos = $this->pedidoPara('registarFactura')->data()['documents'];
        $recibo = $documentos[1];

        $this->assertSame('RC', $recibo['documentType']);
        $this->assertArrayNotHasKey('lines', $recibo);
        $origem = $recibo['paymentReceipt']['sourceDocuments'][0];
        $this->assertSame(1, $origem['lineNo']);
        $this->assertSame('FT FT6325S2C/1', $origem['sourceDocumentID']['OriginatingON']);
        $this->assertEqualsWithDelta(438.59, $origem['creditAmount'], 0.0001);
    }

    public function test_estado_valido_e_invalido_sao_registados(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $this->respostaEstado = [
            'resultCode' => '0',
            'documentStatusList' => [['documentNo' => 'FT FT6325S2C/1', 'documentStatus' => 'V']],
        ];

        $res = app(AgtSubmissaoService::class)->consultarEstados();

        $this->assertSame(1, $res['validos']);
        $this->assertSame('valido', AgtSubmissao::query()->firstOrFail()->estado);
        $this->assertNotNull(AgtSubmissao::query()->firstOrFail()->validado_em);
    }

    public function test_documento_invalido_guarda_os_erros_da_agt(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $this->respostaEstado = [
            'resultCode' => '2',
            'documentStatusList' => [[
                'documentNo' => 'FT FT6325S2C/1',
                'documentStatus' => 'I',
                'errorList' => [['errorCode' => 'E22', 'errorDescription' => 'Total de impostos não coincide']],
            ]],
        ];

        app(AgtSubmissaoService::class)->consultarEstados();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('invalido', $submissao->estado);
        $this->assertSame('E22', $submissao->erros[0]['codigo']);
    }

    public function test_processamento_em_curso_nao_e_consultado_de_novo_antes_do_intervalo(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);
        $servico->enviarPendentes();

        $servico->consultarEstados();
        $servico->consultarEstados();

        $consultas = collect(Http::recorded())->filter(fn ($par) => str_contains($par[0]->url(), 'obterEstado'));
        $this->assertCount(1, $consultas);
        $this->assertSame('enviado', AgtSubmissao::query()->firstOrFail()->estado);
    }

    public function test_pedido_de_estado_leva_jws_signature_do_emissor(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);
        $servico->enviarPendentes();
        $servico->consultarEstados();

        $dados = $this->pedidoPara('obterEstado')->data();

        $this->assertSame('202500000010689', $dados['requestID']);
        $this->assertSame(
            '{"taxRegistrationNumber":"5001636863","requestID":"202500000010689"}',
            Jws::payload($dados['jwsSignature']),
        );
    }

    public function test_rejeicao_por_documento_marca_so_esse_documento(): void
    {
        $this->respostaRegistar = ['errorList' => [['idError' => 'E16', 'descriptionError' => 'Soma a crédito inválida', 'documentNo' => 'FT FT6325S2C/1']]];
        $this->emitirFatura();

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(1, $res['rejeitados']);
        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('rejeitado', $submissao->estado);
        $this->assertSame('E16', $submissao->erros[0]['codigo']);
        $this->assertSame('FT FT6325S2C/1', $submissao->erros[0]['documento']);
    }

    public function test_falha_de_rede_mantem_pendente_e_conta_tentativas(): void
    {
        $this->emitirFatura();

        $this->falharRede = true;
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('pendente', $submissao->estado);
        $this->assertSame(1, $submissao->tentativas);
    }

    public function test_documento_que_nao_pode_ir_para_a_agt_fica_em_erro_sem_bloquear_o_envio(): void
    {
        $this->emitirFatura();

        // Taxa sem código de imposto AGT configurado: o documento não pode ser montado.
        config(['faturacao.agt.codigos_taxa' => []]);

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(1, $res['erros']);
        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('erro', $submissao->estado);
        $this->assertSame('LOCAL', $submissao->erros[0]['codigo']);
        $this->assertStringContainsString('codigos_taxa', $submissao->erros[0]['descricao']);
    }

    public function test_numero_json_de_decimal_remove_zeros_a_direita(): void
    {
        $this->assertSame('250', NumeroJson::deDecimal('250.00')->literal);
        $this->assertSame('0.5', NumeroJson::deDecimal('0.500')->literal);
        $this->assertSame('14', NumeroJson::deDecimal('14.00')->literal);
        $this->assertSame('0', NumeroJson::deDecimal('0.000')->literal);
        $this->assertSame('1.25', NumeroJson::deDecimal('1.250')->literal);
    }
}
