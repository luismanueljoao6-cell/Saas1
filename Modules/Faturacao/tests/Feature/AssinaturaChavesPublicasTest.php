<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Support\Jws;
use Tests\TestCase;

class AssinaturaChavesPublicasTest extends TestCase
{
    use RefreshDatabase;

    protected string $diretorio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diretorio = sys_get_temp_dir().'/faturacao-chaves-'.uniqid('', true);
        mkdir($this->diretorio, 0700, true);
        config(['faturacao.chaves_dir' => $this->diretorio]);

        $this->beforeApplicationDestroyed(function (): void {
            foreach (glob($this->diretorio.'/*') ?: [] as $ficheiro) {
                @unlink($ficheiro);
            }

            @rmdir($this->diretorio);
        });
    }

    protected function empresa(string $nif): Empresa
    {
        return Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nif' => $nif,
        ]);
    }

    protected function guardarChavePrivada(string $nif): void
    {
        $chave = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($chave);
        $this->assertTrue(openssl_pkey_export($chave, $pem));
        file_put_contents($this->diretorio.'/'.$nif.'.pem', $pem);
    }

    /** @return array{0: string, 1: string} [chave privada, chave pública] */
    protected function gerarParChaves(): array
    {
        $chave = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($chave);
        $this->assertTrue(openssl_pkey_export($chave, $privada));
        $detalhes = openssl_pkey_get_details($chave);
        $this->assertIsArray($detalhes);
        $this->assertIsString($detalhes['key'] ?? null);

        return [$privada, $detalhes['key']];
    }

    public function test_jws_valido_e_verificado_criptograficamente(): void
    {
        [$privada, $publica] = $this->gerarParChaves();
        $chavePrivada = openssl_pkey_get_private($privada);
        $this->assertNotFalse($chavePrivada);
        $payload = '{"documentNo":"FT 2026/1","grossTotal":114.00}';
        $jws = Jws::assinar($payload, $chavePrivada);

        $this->assertTrue(app(AssinaturaFiscalService::class)->verificarComPem($payload, $jws, $publica));
    }

    public function test_jws_com_assinatura_adulterada_e_rejeitado(): void
    {
        [$privada, $publica] = $this->gerarParChaves();
        $chavePrivada = openssl_pkey_get_private($privada);
        $this->assertNotFalse($chavePrivada);
        $jws = Jws::assinar('{"documentNo":"FT 2026/2"}', $chavePrivada);
        $partes = explode('.', $jws);
        $partes[2] = Jws::base64url('assinatura adulterada');

        $this->assertFalse(app(AssinaturaFiscalService::class)->verificarComPem(
            (string) Jws::payload($jws),
            implode('.', $partes),
            $publica,
        ));
    }

    public function test_jws_com_payload_alterado_e_rejeitado(): void
    {
        [$privada, $publica] = $this->gerarParChaves();
        $chavePrivada = openssl_pkey_get_private($privada);
        $this->assertNotFalse($chavePrivada);
        $jws = Jws::assinar('{"documentNo":"FT 2026/3"}', $chavePrivada);
        $partes = explode('.', $jws);
        $partes[1] = Jws::base64url('{"documentNo":"FT 2026/999"}');
        $alterado = implode('.', $partes);

        $this->assertFalse(app(AssinaturaFiscalService::class)->verificarComPem(
            '{"documentNo":"FT 2026/999"}',
            $alterado,
            $publica,
        ));
    }

    public function test_jws_assinado_com_outra_chave_e_rejeitado(): void
    {
        [$privadaA] = $this->gerarParChaves();
        [, $publicaB] = $this->gerarParChaves();
        $chavePrivada = openssl_pkey_get_private($privadaA);
        $this->assertNotFalse($chavePrivada);
        $payload = '{"documentNo":"FT 2026/4"}';
        $jws = Jws::assinar($payload, $chavePrivada);

        $this->assertFalse(app(AssinaturaFiscalService::class)->verificarComPem($payload, $jws, $publicaB));
    }

    public function test_chave_publica_ausente_e_rejeitada(): void
    {
        $this->expectException(AssinaturaFiscalException::class);

        app(AssinaturaFiscalService::class)->verificarComPem('{"documentNo":"FT 2026/5"}', 'jws.invalido.assinatura', '');
    }

    public function test_comando_de_auditoria_das_chaves_esta_registado(): void
    {
        $this->assertArrayHasKey('faturacao:verificar-chaves', Artisan::all());
    }

    public function test_arquiva_e_resolve_a_chave_publica_da_empresa_por_versao(): void
    {
        $empresa = $this->empresa('5001636863');
        $this->guardarChavePrivada($empresa->nif);

        $servico = app(AssinaturaFiscalService::class);
        $servico->arquivarChavePublicaDaEmpresa((int) $empresa->id, 1);

        $this->assertNotNull($servico->chavePublicaDaVersao((int) $empresa->id, 1));
        $this->assertNull($servico->chavePublicaDaVersao((int) $empresa->id, 2));
    }

    public function test_versao_arquivada_nao_pode_ser_reutilizada_com_outra_chave(): void
    {
        $empresa = $this->empresa('5001636864');
        $this->guardarChavePrivada($empresa->nif);

        $servico = app(AssinaturaFiscalService::class);
        $servico->arquivarChavePublicaDaEmpresa((int) $empresa->id, 1);
        $chaveOriginal = $servico->chavePublicaDaVersao((int) $empresa->id, 1);

        $this->guardarChavePrivada($empresa->nif);
        $servico = app(AssinaturaFiscalService::class);

        try {
            $servico->arquivarChavePublicaDaEmpresa((int) $empresa->id, 1);
            $this->fail('A mesma versão não pode ser associada a outra chave.');
        } catch (AssinaturaFiscalException $e) {
            $this->assertStringContainsString('incrementa FATURACAO_CHAVE_VERSAO', $e->getMessage());
        }

        $this->assertSame($chaveOriginal, $servico->chavePublicaDaVersao((int) $empresa->id, 1));
    }

    public function test_rotacao_preserva_a_chave_publica_historica(): void
    {
        $empresa = $this->empresa('5001636867');
        $this->guardarChavePrivada($empresa->nif);

        $primeiroServico = app(AssinaturaFiscalService::class);
        $primeiroServico->arquivarChavePublicaDaEmpresa((int) $empresa->id, 1);
        $chaveVersaoUm = $primeiroServico->chavePublicaDaVersao((int) $empresa->id, 1);

        $this->guardarChavePrivada($empresa->nif);
        $segundoServico = app(AssinaturaFiscalService::class);
        $segundoServico->arquivarChavePublicaDaEmpresa((int) $empresa->id, 2);

        $this->assertSame($chaveVersaoUm, $segundoServico->chavePublicaDaVersao((int) $empresa->id, 1));
        $this->assertNotSame($chaveVersaoUm, $segundoServico->chavePublicaDaVersao((int) $empresa->id, 2));
    }

    public function test_chaves_de_empresas_diferentes_ficam_isoladas(): void
    {
        $empresaA = $this->empresa('5001636865');
        $empresaB = $this->empresa('5001636866');
        $this->guardarChavePrivada($empresaA->nif);
        $this->guardarChavePrivada($empresaB->nif);

        $servico = app(AssinaturaFiscalService::class);
        $servico->arquivarChavePublicaDaEmpresa((int) $empresaA->id, 1);
        $servico->arquivarChavePublicaDaEmpresa((int) $empresaB->id, 1);

        $this->assertNotSame(
            $servico->chavePublicaDaVersao((int) $empresaA->id, 1),
            $servico->chavePublicaDaVersao((int) $empresaB->id, 1),
        );
    }
}
