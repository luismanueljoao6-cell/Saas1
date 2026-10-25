<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Support\Jws;
use Tests\TestCase;

/** Chaves de assinatura: uma por empresa, nunca a genérica fora de desenvolvimento, e auditáveis. */
class ChavesVerificacaoTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected string $generica;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/chaves-'.uniqid();
        mkdir($this->dir);
        $this->generica = $this->dir.'/generica.pem';
        $this->gerarChave($this->generica);

        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->dir.'/*') ?: [] as $ficheiro) {
                @unlink($ficheiro);
            }
            @rmdir($this->dir);
        });

        config([
            'faturacao.chaves_dir' => $this->dir,
            'faturacao.chave_privada_path' => $this->generica,
            'faturacao.agt.ativo' => false,
        ]);
    }

    protected function gerarChave(string $caminho, int $bits = 2048): string
    {
        $recurso = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($caminho, $pem);
        chmod($caminho, 0600);

        return $caminho;
    }

    protected function empresa(string $nif): Empresa
    {
        return Empresa::create(['nome_comercial' => "Empresa {$nif}", 'nif' => $nif]);
    }

    /** @return array{0: int, 1: string} */
    protected function auditar(array $opcoes = []): array
    {
        $codigo = Artisan::call('faturacao:verificar-chaves', $opcoes);

        return [$codigo, Artisan::output()];
    }

    public function test_assinar_sem_empresa_e_recusado_em_producao(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(AssinaturaFiscalException::class);

        app(AssinaturaFiscalService::class)->assinar('{"a":1}');
    }

    public function test_assinar_sem_empresa_e_recusado_com_a_agt_ativa(): void
    {
        config(['faturacao.agt.ativo' => true]);

        $this->expectException(AssinaturaFiscalException::class);

        app(AssinaturaFiscalService::class)->assinar('{"a":1}');
    }

    public function test_assinar_sem_empresa_continua_a_funcionar_em_testes(): void
    {
        $jws = app(AssinaturaFiscalService::class)->assinar('{"a":1}');

        $this->assertSame('{"a":1}', Jws::payload($jws));
    }

    public function test_chave_publica_da_empresa_verifica_o_que_a_empresa_assinou(): void
    {
        $empresa = $this->empresa('5001636863');
        $this->gerarChave($this->dir.'/5001636863.pem');

        $servico = app(AssinaturaFiscalService::class);
        $jws = $servico->assinar('{"a":1}', $empresa->id);

        $this->assertTrue($servico->verificarComPem('{"a":1}', $jws, (string) $servico->chavePublicaDaEmpresa($empresa->id)));
        $this->assertFalse($servico->verificarComPem('{"a":2}', $jws, (string) $servico->chavePublicaDaEmpresa($empresa->id)));
    }

    public function test_chave_publica_da_empresa_nunca_cai_na_chave_generica(): void
    {
        $empresa = $this->empresa('5001636863'); // sem ficheiro {NIF}.pem

        $this->assertNull(app(AssinaturaFiscalService::class)->chavePublicaDaEmpresa($empresa->id));
    }

    public function test_verificacao_passa_com_uma_chave_por_empresa(): void
    {
        $this->empresa('5001636863');
        $this->empresa('5001636864');
        $this->gerarChave($this->dir.'/5001636863.pem');
        $this->gerarChave($this->dir.'/5001636864.pem');

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(0, $codigo, $saida);
        $this->assertStringContainsString('2 chave(s)', $saida);
    }

    public function test_empresa_sem_chave_e_aviso_em_desenvolvimento_e_problema_com_a_agt_ativa(): void
    {
        $this->empresa('5001636863');

        [$codigo, $saida] = $this->auditar();
        $this->assertSame(0, $codigo, $saida);
        $this->assertStringContainsString('chave em falta', $saida);

        config(['faturacao.agt.ativo' => true, 'faturacao.agt.software.chave_privada_path' => $this->gerarChave($this->dir.'/software.pem')]);

        [$codigo, $saida] = $this->auditar();
        $this->assertSame(1, $codigo, $saida);
        $this->assertStringContainsString('chave em falta', $saida);
    }

    public function test_duas_empresas_com_a_mesma_chave_falham(): void
    {
        $this->empresa('5001636863');
        $this->empresa('5001636864');
        $this->gerarChave($this->dir.'/5001636863.pem');
        copy($this->dir.'/5001636863.pem', $this->dir.'/5001636864.pem');

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('mesma chave', $saida);
    }

    public function test_empresa_com_a_chave_generica_falha(): void
    {
        $this->empresa('5001636863');
        copy($this->generica, $this->dir.'/5001636863.pem');

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('chave genérica', $saida);
    }

    public function test_chave_com_menos_de_2048_bits_falha(): void
    {
        $this->empresa('5001636863');
        $this->gerarChave($this->dir.'/5001636863.pem', 1024);

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('1024 bits', $saida);
    }

    public function test_com_a_agt_ativa_a_chave_do_software_tem_de_ser_distinta_das_dos_emissores(): void
    {
        $this->empresa('5001636863');
        $this->gerarChave($this->dir.'/5001636863.pem');
        copy($this->dir.'/5001636863.pem', $this->dir.'/software.pem');
        config(['faturacao.agt.ativo' => true, 'faturacao.agt.software.chave_privada_path' => $this->dir.'/software.pem']);

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('chave do software', $saida);
    }

    public function test_permissoes_abertas_no_ficheiro_da_chave_geram_aviso(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('Permissões POSIX.');
        }

        $this->empresa('5001636863');
        chmod($this->gerarChave($this->dir.'/5001636863.pem'), 0644);

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(0, $codigo, $saida);
        $this->assertStringContainsString('chmod 600', $saida);
    }

    public function test_opcao_empresa_limita_a_verificacao(): void
    {
        $a = $this->empresa('5001636863');
        $this->empresa('5001636864'); // sem chave: ficaria como aviso, mas fora do âmbito
        $this->gerarChave($this->dir.'/5001636863.pem');

        [$codigo, $saida] = $this->auditar(['--empresa' => $a->id]);

        $this->assertSame(0, $codigo, $saida);
        $this->assertStringNotContainsString('chave em falta', $saida);
    }
}
