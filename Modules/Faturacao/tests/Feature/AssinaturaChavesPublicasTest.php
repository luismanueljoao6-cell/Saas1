<?php

namespace Modules\\Faturacao\\Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Modules\\Core\\Models\\Empresa;
use Modules\\Faturacao\\Exceptions\\AssinaturaFiscalException;
use Modules\\Faturacao\\Services\\AssinaturaFiscalService;
use Tests\\TestCase;

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

        try {
            $servico->arquivarChavePublicaDaEmpresa((int) $empresa->id, 1);
            $this->fail('A mesma versão não pode ser associada a outra chave.');
        } catch (AssinaturaFiscalException $e) {
            $this->assertStringContainsString('incrementa FATURACAO_CHAVE_VERSAO', $e->getMessage());
        }

        $this->assertSame($chaveOriginal, $servico->chavePublicaDaVersao((int) $empresa->id, 1));
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
