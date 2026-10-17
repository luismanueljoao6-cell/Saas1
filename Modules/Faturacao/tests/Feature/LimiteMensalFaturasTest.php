<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\EmissaoNaoPermitidaException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Tests\TestCase;

class LimiteMensalFaturasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/faturacao-limite-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);

        config(['faturacao.chave_privada_path' => $privada]);

        $this->beforeApplicationDestroyed(function () use ($privada) {
            @unlink($privada);
        });
    }

    protected function definirLimiteMensal(?int $limite): void
    {
        $this->app->bind(LimitesDaEmpresa::class, fn () => new class($limite) implements LimitesDaEmpresa
        {
            public function __construct(protected ?int $limite) {}

            public function limite(Empresa $empresa, string $chave): ?int
            {
                return $chave === 'max_faturas_mes' ? $this->limite : null;
            }
        });
    }

    protected function empresaComCliente(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja Limite '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        return [$empresa, $cliente];
    }

    protected function rascunho(Empresa $empresa, Cliente $cliente): Fatura
    {
        return app(FaturaService::class)->criarRascunho($empresa, $cliente, [
            ['descricao' => 'Serviço de exemplo', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14],
        ]);
    }

    protected function emitir(Empresa $empresa, Cliente $cliente): Fatura
    {
        return app(FaturaService::class)->emitir($this->rascunho($empresa, $cliente));
    }

    public function test_emissao_e_bloqueada_quando_o_limite_mensal_e_atingido(): void
    {
        $this->definirLimiteMensal(2);
        [$empresa, $cliente] = $this->empresaComCliente('840000001');

        $this->emitir($empresa, $cliente);
        $this->emitir($empresa, $cliente);

        $terceira = $this->rascunho($empresa, $cliente);

        try {
            app(FaturaService::class)->emitir($terceira);
            $this->fail('A terceira fatura do mês devia ser bloqueada.');
        } catch (EmissaoNaoPermitidaException $e) {
            $this->assertStringContainsString('no máximo 2', $e->getMessage());
        }

        $this->assertSame('rascunho', Fatura::withoutGlobalScopes()->findOrFail($terceira->id)->estado);
    }

    public function test_sem_limite_emite_sem_restricao(): void
    {
        $this->definirLimiteMensal(null);
        [$empresa, $cliente] = $this->empresaComCliente('840000002');

        foreach (range(1, 3) as $ignorado) {
            $this->assertSame('emitida', $this->emitir($empresa, $cliente)->estado);
        }
    }

    public function test_reemitir_fatura_ja_emitida_nao_falha_no_limite(): void
    {
        $this->definirLimiteMensal(1);
        [$empresa, $cliente] = $this->empresaComCliente('840000003');

        $emitida = $this->emitir($empresa, $cliente);
        $repetida = app(FaturaService::class)->emitir($emitida);

        $this->assertSame($emitida->numero_documento, $repetida->numero_documento);
    }

    public function test_faturas_do_mes_anterior_nao_contam_para_o_limite(): void
    {
        $this->definirLimiteMensal(1);
        [$empresa, $cliente] = $this->empresaComCliente('840000004');

        $this->travelTo(now()->startOfMonth()->subDay());
        $this->emitir($empresa, $cliente);
        $this->travelBack();

        // Este mês ainda não há nenhuma: a primeira passa, a segunda não.
        $this->emitir($empresa, $cliente);

        $this->expectException(EmissaoNaoPermitidaException::class);
        $this->emitir($empresa, $cliente);
    }
}
