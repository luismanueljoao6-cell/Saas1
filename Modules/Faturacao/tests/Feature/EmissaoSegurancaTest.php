<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\CadeiaFiscalInterrompidaException;
use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NumeracaoService;
use Modules\Faturacao\Services\ReciboService;
use Tests\TestCase;

class EmissaoSegurancaTest extends TestCase
{
    use RefreshDatabase;

    protected string $caminhoChavePublica;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        $this->caminhoChavePublica = sys_get_temp_dir().'/faturacao-teste-pub-'.uniqid().'.pem';

        file_put_contents($privada, $privadaPem);
        file_put_contents($this->caminhoChavePublica, openssl_pkey_get_details($recurso)['key']);

        config(['faturacao.chave_privada_path' => $privada]);

        $publica = $this->caminhoChavePublica;
        $this->beforeApplicationDestroyed(function () use ($privada, $publica) {
            @unlink($privada);
            @unlink($publica);
        });
    }

    protected function criarEmpresaComCliente(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja Teste '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        return [$empresa, $cliente];
    }

    protected function linhaExemplo(): array
    {
        return [['descricao' => 'Serviço de exemplo', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]];
    }

    protected function emitirFatura(Empresa $empresa, Cliente $cliente): Fatura
    {
        $servico = app(FaturaService::class);

        return $servico->emitir($servico->criarRascunho($empresa, $cliente, $this->linhaExemplo()));
    }

    public function test_lacuna_na_cadeia_interrompe_a_emissao_em_vez_de_gravar_hash_vazio(): void
    {
        [$empresa] = $this->criarEmpresaComCliente('310000001');
        $numeracao = app(NumeracaoService::class);
        $serie = $numeracao->obterOuCriarSerie($empresa->id, 'FT');

        DB::transaction(fn () => $this->assertNull($numeracao->hashDoDocumentoAnterior(Fatura::class, $serie->id, 1)));

        $this->expectException(CadeiaFiscalInterrompidaException::class);

        DB::transaction(fn () => $numeracao->hashDoDocumentoAnterior(Fatura::class, $serie->id, 5));
    }

    public function test_hash_anterior_e_lido_do_documento_emitido(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('310000002');
        $primeira = $this->emitirFatura($empresa, $cliente);

        $hash = DB::transaction(
            fn () => app(NumeracaoService::class)->hashDoDocumentoAnterior(Fatura::class, $primeira->serie_id, 2)
        );

        $this->assertSame($primeira->hash, $hash);
    }

    public function test_emissao_de_fatura_e_bloqueada_com_subscricao_inativa(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('310000003');
        $rascunho = app(FaturaService::class)->criarRascunho($empresa, $cliente, $this->linhaExemplo());

        $empresa->forceFill(['estado_subscricao' => 'expirada'])->save();

        $this->expectException(EmissaoNaoPermitidaException::class);

        app(FaturaService::class)->emitir($rascunho);
    }

    public function test_recibos_encadeiam_e_nunca_excedem_o_valor_da_fatura(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('310000004');
        $fatura = $this->emitirFatura($empresa, $cliente); // 1000 + 14% = 1140.00
        $recibos = app(ReciboService::class);

        $r1 = $recibos->emitir($empresa->id, $cliente->id, '500.00', 'dinheiro', $fatura->id);
        $r2 = $recibos->emitir($empresa->id, $cliente->id, 640, 'transferencia', $fatura->id);

        $this->assertSame('emitido', $r2->estado);
        $this->assertNull($r1->hash_anterior);
        $this->assertSame($r1->hash, $r2->hash_anterior);

        $this->expectException(\DomainException::class);

        $recibos->emitir($empresa->id, $cliente->id, 1, 'dinheiro', $fatura->id);
    }

    public function test_recibo_recusa_cliente_de_outra_empresa(): void
    {
        [$empresa] = $this->criarEmpresaComCliente('310000005');
        [, $clienteOutro] = $this->criarEmpresaComCliente('310000006');

        $this->expectException(\DomainException::class);

        app(ReciboService::class)->emitir($empresa->id, $clienteOutro->id, 10, 'dinheiro');
    }

    public function test_comando_de_auditoria_passa_numa_cadeia_integra(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('310000007');
        $fatura = $this->emitirFatura($empresa, $cliente);
        $this->emitirFatura($empresa, $cliente);
        app(ReciboService::class)->emitir($empresa->id, $cliente->id, 100, 'dinheiro', $fatura->id);

        $this->artisan('faturacao:verificar-cadeia', ['--chave-publica' => $this->caminhoChavePublica])
            ->assertExitCode(0);
    }
}
