<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Support\Jws;
use Tests\TestCase;

class ChaveGenericaTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $generica = sys_get_temp_dir().'/chave-generica-'.uniqid().'.pem';
        file_put_contents($generica, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($generica));

        config([
            'faturacao.chave_privada_path' => $generica,
            'faturacao.chaves_dir' => sys_get_temp_dir().'/sem-chaves-'.uniqid(),
            'faturacao.agt.ativo' => false,
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Sem Chave', 'nif' => '5001636863']);
    }

    public function test_em_testes_com_agt_desligada_a_chave_generica_e_aceite(): void
    {
        $jws = app(AssinaturaFiscalService::class)->assinar('{"a":1}', $this->empresa->id);

        $this->assertSame('{"a":1}', Jws::payload($jws));
    }

    public function test_com_agt_ativa_a_chave_generica_e_recusada(): void
    {
        config(['faturacao.agt.ativo' => true]);

        $this->expectException(AssinaturaFiscalException::class);

        app(AssinaturaFiscalService::class)->assinar('{"a":1}', $this->empresa->id);
    }

    public function test_em_producao_a_chave_generica_e_recusada(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(AssinaturaFiscalException::class);

        app(AssinaturaFiscalService::class)->assinar('{"a":1}', $this->empresa->id);
    }
}
