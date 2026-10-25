<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Serie;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\FaturaService;
use Tests\TestCase;

/** Auditoria da cadeia com várias empresas (uma chave cada) e com correspondência AGT. */
class VerificarCadeiaMultiEmpresaTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/cadeia-'.uniqid();
        mkdir($this->dir);
        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        });

        $this->gerarChave($this->dir.'/generica.pem');
        // Isolado do que existir no storage real (ex.: um chave-publica.pem de desenvolvimento).
        config([
            'faturacao.chaves_dir' => $this->dir,
            'faturacao.chave_privada_path' => $this->dir.'/generica.pem',
            'faturacao.chave_publica_path' => $this->dir.'/nao-existe.pub.pem',
            'faturacao.agt.ativo' => false,
        ]);
    }

    protected function gerarChave(string $caminho): string
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($caminho, $pem);

        return $caminho;
    }

    /** @return array{0: Empresa, 1: Cliente} */
    protected function empresaComChave(string $nif): array
    {
        $this->gerarChave("{$this->dir}/{$nif}.pem");
        $empresa = Empresa::create(['nome_comercial' => "Empresa {$nif}", 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);

        return [$empresa, $cliente];
    }

    protected function emitir(Empresa $empresa, Cliente $cliente): Fatura
    {
        $faturas = app(FaturaService::class);

        return $faturas->emitir($faturas->criarRascunho($empresa, $cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14],
        ]));
    }

    /** @return array{0: int, 1: string} */
    protected function auditar(array $opcoes = []): array
    {
        $codigo = Artisan::call('faturacao:verificar-cadeia', $opcoes);

        return [$codigo, Artisan::output()];
    }

    public function test_cada_empresa_e_verificada_com_a_sua_propria_chave(): void
    {
        [$a, $clienteA] = $this->empresaComChave('5001636863');
        [$b, $clienteB] = $this->empresaComChave('5001636864');
        $this->emitir($a, $clienteA);
        $this->emitir($a, $clienteA);
        $this->emitir($b, $clienteB);

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(0, $codigo, $saida);
        $this->assertStringContainsString('3 documento(s) verificados', $saida);
    }

    public function test_assinatura_com_outra_chave_e_detetada(): void
    {
        [$a, $clienteA] = $this->empresaComChave('5001636863');
        $this->emitir($a, $clienteA);

        // A chave da empresa é trocada depois da emissão: o documento já não confere.
        $this->gerarChave($this->dir.'/5001636863.pem');

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('assinatura RSA inválida', $saida);
    }

    public function test_sem_chave_publica_a_empresa_fica_assinalada(): void
    {
        [$a, $clienteA] = $this->empresaComChave('5001636863');
        $this->emitir($a, $clienteA);
        unlink($this->dir.'/5001636863.pem');

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('sem chave pública', $saida);
    }

    public function test_chave_publica_indicada_continua_a_valer_para_todas_as_empresas(): void
    {
        [$a, $clienteA] = $this->empresaComChave('5001636863');
        $this->emitir($a, $clienteA);

        $publica = $this->dir.'/publica.pem';
        $privada = openssl_pkey_get_private((string) file_get_contents($this->dir.'/5001636863.pem'));
        file_put_contents($publica, openssl_pkey_get_details($privada)['key']);

        [$codigo] = $this->auditar(['--chave-publica' => $publica]);
        $this->assertSame(0, $codigo);

        [$codigo, $saida] = $this->auditar(['--chave-publica' => $this->dir.'/nao-existe.pem']);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Chave pública não encontrada', $saida);
    }

    protected function prepararAgt(Empresa $empresa): void
    {
        $software = $this->gerarChave($this->dir.'/software.pem');

        config([
            'faturacao.agt.ativo' => true,
            'faturacao.agt.ambiente' => 'homologacao',
            'faturacao.agt.utilizador' => 'produtor',
            'faturacao.agt.palavra_passe' => 's3nh@',
            'faturacao.agt.software.chave_privada_path' => $software,
            'faturacao.agt.software.signature_version' => null,
            'faturacao.saft_numero_certificado' => 'C_134',
        ]);

        Http::fake([
            '*solicitarSerie' => fn (Request $r) => Http::response([
                'resultCode' => 1,
                'errorList' => [''],
                'seriesFEResult' => ['seriesCode' => $r['documentType'].'6325S2C', 'authorizedQuantity' => '1000', 'firstDocumentNo' => '1', 'lastDocumentNo' => '1000'],
            ], 200),
        ]);

        app(AgtSerieService::class)->solicitar($empresa, 'FT', now()->year);
    }

    public function test_com_agt_cada_documento_tem_de_ter_submissao_e_so_valido_e_confirmacao(): void
    {
        [$a, $clienteA] = $this->empresaComChave('5001636863');
        $this->prepararAgt($a);
        $fatura = $this->emitir($a, $clienteA);

        // Pendente: a cadeia está íntegra, mas a AGT ainda não confirmou — aviso, não erro.
        [$codigo, $saida] = $this->auditar(['--agt' => true]);
        $this->assertSame(0, $codigo, $saida);
        $this->assertStringContainsString('Pendente de envio', $saida);

        AgtSubmissao::query()->where('documento_id', $fatura->id)->update(['estado' => AgtSubmissao::VALIDO]);
        [$codigo, $saida] = $this->auditar(['--agt' => true]);
        $this->assertSame(0, $codigo, $saida);
        $this->assertStringNotContainsString('Pendente', $saida);

        AgtSubmissao::query()->where('documento_id', $fatura->id)->delete();
        [$codigo, $saida] = $this->auditar(['--agt' => true]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('sem registo de submissão', $saida);
    }

    public function test_numero_fora_do_intervalo_autorizado_pela_agt_e_detetado(): void
    {
        [$a, $clienteA] = $this->empresaComChave('5001636863');
        $this->prepararAgt($a);
        $this->emitir($a, $clienteA);
        $this->emitir($a, $clienteA);

        Serie::withoutGlobalScopes()->where('empresa_id', $a->id)->update(['ultimo_numero_autorizado' => 1]);

        [$codigo, $saida] = $this->auditar();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('fora do intervalo autorizado', $saida);
    }
}
