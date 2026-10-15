<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Services\AgtClient;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\NumeracaoService;
use Modules\Faturacao\Support\Jws;
use Tests\TestCase;

class AgtSerieTest extends TestCase
{
    use RefreshDatabase;

    protected string $publicaEmpresa;

    protected string $publicaSoftware;

    protected string $ultimoAutorizado = '1000';

    protected bool $agtSimulada = false;

    protected function setUp(): void
    {
        parent::setUp();

        [$privadaEmpresa, $this->publicaEmpresa] = $this->gerarPar();
        [$privadaSoftware, $this->publicaSoftware] = $this->gerarPar();

        config([
            'faturacao.chave_privada_path' => $privadaEmpresa,
            'faturacao.chaves_dir' => sys_get_temp_dir().'/agt-sem-chaves-'.uniqid(),
            'faturacao.agt.software.chave_privada_path' => $privadaSoftware,
            'faturacao.agt.software.product_id' => 'Saas1 Teste',
            'faturacao.agt.software.product_version' => '1.0.0',
            'faturacao.agt.software.signature_version' => null,
            'faturacao.agt.utilizador' => 'produtor',
            'faturacao.agt.palavra_passe' => 's3nh@',
            'faturacao.agt.ambiente' => 'homologacao',
            'faturacao.saft_numero_certificado' => 'C_134',
        ]);
    }

    /** @return array{0: string, 1: string} caminhos [privada, publica] */
    protected function gerarPar(): array
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/agt-priv-'.uniqid().'.pem';
        $publica = sys_get_temp_dir().'/agt-pub-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);
        file_put_contents($publica, openssl_pkey_get_details($recurso)['key']);

        $this->beforeApplicationDestroyed(function () use ($privada, $publica) {
            @unlink($privada);
            @unlink($publica);
        });

        return [$privada, $publica];
    }

    protected function empresa(): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
    }

    protected function respostaSerie(string $ultimo = '1000'): void
    {
        $this->ultimoAutorizado = $ultimo;

        if ($this->agtSimulada) {
            return;
        }

        $this->agtSimulada = true;

        // Um só Http::fake(): chamadas seguintes não substituem as respostas anteriores
        // (ganha a primeira que corresponder), por isso a resposta lê a propriedade.
        Http::fake(['*' => fn () => Http::response([
            'resultCode' => 1,
            'errorList' => [''],
            'seriesFEResult' => [
                'seriesCode' => 'FT6325S2C',
                'authorizedQuantity' => $this->ultimoAutorizado,
                'firstDocumentNo' => '1',
                'lastDocumentNo' => $this->ultimoAutorizado,
            ],
        ], 200)]);
    }

    public function test_solicitar_serie_envia_pedido_assinado_e_guarda_o_intervalo_autorizado(): void
    {
        $this->respostaSerie();
        $serie = app(AgtSerieService::class)->solicitar($this->empresa(), 'FT', 2026);

        $this->assertSame('FT6325S2C', $serie->agt_codigo);
        $this->assertSame('SEDE', $serie->estabelecimento);
        $this->assertSame(0, $serie->ultimo_numero);
        $this->assertSame(1000, $serie->ultimo_numero_autorizado);

        [$pedido] = Http::recorded()[0];
        $dados = $pedido->data();

        $this->assertSame('https://sifphml.minfin.gov.ao/sigt/fe/v1/solicitarSerie', $pedido->url());
        $this->assertSame('Basic '.base64_encode('produtor:s3nh@'), $pedido->header('Authorization')[0]);
        $this->assertSame('2.0', $dados['schemaVersion']);
        $this->assertSame('5001636863', $dados['taxRegistrationNumber']);
        $this->assertSame('FT', $dados['documentType']);
        $this->assertSame('N', $dados['seriesContingencyIndicator']);

        // jwsSignature (chave do emissor): objecto exacto indicado pela AGT.
        $this->assertSame(
            '{"taxRegistrationNumber":"5001636863","seriesYear":2026,"documentType":"FT","establishmentNumber":"SEDE","seriesContingencyIndicator":"N"}',
            Jws::payload($dados['jwsSignature']),
        );
        $this->assertTrue(Jws::verificar($dados['jwsSignature'], openssl_pkey_get_public((string) file_get_contents($this->publicaEmpresa))));

        // jwsSoftwareSignature (chave do software): softwareInfoDetail inteiro.
        $assinaturaSoftware = $dados['softwareInfo']['jwsSoftwareSignature'];
        $this->assertSame('{"productId":"Saas1 Teste","productVersion":"1.0.0","softwareValidationNumber":"C_134"}', Jws::payload($assinaturaSoftware));
        $this->assertTrue(Jws::verificar($assinaturaSoftware, openssl_pkey_get_public((string) file_get_contents($this->publicaSoftware))));
    }

    public function test_numeracao_com_serie_agt_usa_o_codigo_da_serie_e_respeita_o_limite(): void
    {
        config(['faturacao.agt.ativo' => true]);
        $empresa = $this->empresa();
        $this->respostaSerie('2');
        app(AgtSerieService::class)->solicitar($empresa, 'FT', now()->year);

        $numeracao = app(NumeracaoService::class);

        $this->assertSame('FT FT6325S2C/1', $numeracao->proximoNumero($empresa->id, 'FT')['numero_documento']);
        $this->assertSame('FT FT6325S2C/2', $numeracao->proximoNumero($empresa->id, 'FT')['numero_documento']);

        $this->expectException(AgtException::class);
        $this->expectExceptionMessage('esgotou');

        $numeracao->proximoNumero($empresa->id, 'FT');
    }

    public function test_extensao_de_serie_aumenta_o_limite_sem_reiniciar_a_numeracao(): void
    {
        config(['faturacao.agt.ativo' => true]);
        $empresa = $this->empresa();
        $this->respostaSerie('1');
        app(AgtSerieService::class)->solicitar($empresa, 'FT', now()->year);
        app(NumeracaoService::class)->proximoNumero($empresa->id, 'FT');

        $this->respostaSerie('5');
        $serie = app(AgtSerieService::class)->solicitar($empresa, 'FT', now()->year);

        $this->assertSame(1, $serie->ultimo_numero);
        $this->assertSame(5, $serie->ultimo_numero_autorizado);
        $this->assertSame('FT FT6325S2C/2', app(NumeracaoService::class)->proximoNumero($empresa->id, 'FT')['numero_documento']);
    }

    public function test_com_agt_ativa_nao_se_inventa_serie_local(): void
    {
        config(['faturacao.agt.ativo' => true]);
        $empresa = $this->empresa();

        $this->expectException(AgtException::class);
        $this->expectExceptionMessage('faturacao:agt-solicitar-serie');

        app(NumeracaoService::class)->obterOuCriarSerie($empresa->id, 'FT');
    }

    public function test_rejeicao_da_agt_devolve_os_codigos_de_erro(): void
    {
        Http::fake(['*' => Http::response(['errorList' => [['idError' => 'E31', 'descriptionError' => 'Código de série já em utilização']]], 400)]);

        try {
            app(AgtSerieService::class)->solicitar($this->empresa(), 'FT', 2026);
        } catch (AgtException $e) {
            $this->assertSame('E31', $e->erros[0]['codigo']);
            $this->assertStringContainsString('E31', $e->getMessage());

            return;
        }

        $this->fail('Era esperada uma AgtException.');
    }

    public function test_sem_credenciais_nada_e_enviado(): void
    {
        Http::fake();
        config(['faturacao.agt.utilizador' => '', 'faturacao.agt.palavra_passe' => '']);

        try {
            app(AgtSerieService::class)->solicitar($this->empresa(), 'FT', 2026);
            $this->fail('Era esperada uma AgtException.');
        } catch (AgtException $e) {
            $this->assertStringContainsString('FATURACAO_AGT_UTILIZADOR', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_producao_exige_numero_de_certificacao_do_software(): void
    {
        Http::fake();
        config(['faturacao.agt.ambiente' => 'producao', 'faturacao.saft_numero_certificado' => '0']);

        $this->expectException(AgtException::class);
        $this->expectExceptionMessage('certificação');

        try {
            app(AgtSerieService::class)->solicitar($this->empresa(), 'FT', 2026);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_normalizar_erros_aceita_objectos_e_textos_e_ignora_vazios(): void
    {
        $erros = AgtClient::normalizarErros([
            '',
            'falha solta',
            ['idError' => 'E09', 'descriptionError' => 'Factura já consta no repositório'],
        ]);

        $this->assertSame([
            ['codigo' => 'E99', 'descricao' => 'falha solta'],
            ['codigo' => 'E09', 'descricao' => 'Factura já consta no repositório'],
        ], $erros);
    }
}
