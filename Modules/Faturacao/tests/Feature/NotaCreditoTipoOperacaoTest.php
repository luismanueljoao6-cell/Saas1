<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Tests\TestCase;

/**
 * Uma nota de crédito parcial declara o mesmo operationType da fatura que corrige:
 * sem isto, o AgtDocumentoBuilder aplicava o tipo padrão (SG) a linhas que eram TB.
 */
class NotaCreditoTipoOperacaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        config([
            'faturacao.chave_privada_path' => $caminho,
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Tipos', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    /** @return array<string, mixed> */
    protected function linha(string $descricao, int $preco, ?string $tipo = null): array
    {
        $linha = ['descricao' => $descricao, 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => 14];

        if ($tipo !== null) {
            $linha['operation_type'] = $tipo;
        }

        return $linha;
    }

    /** @param  array<int, array<string, mixed>>  $linhas */
    protected function faturaEmitida(array $linhas): Fatura
    {
        $servico = app(FaturaService::class);
        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, $linhas));

        $esperado = array_map(fn (array $l) => $l['operation_type'], $linhas);
        $this->assertSame($esperado, $fatura->linhas()->pluck('operation_type')->all(), 'pré-condição: a fatura guarda o tipo de operação das linhas');

        return $fatura;
    }

    protected function faturaMista(): Fatura
    {
        return $this->faturaEmitida([
            $this->linha('Serviço A', 1000, 'SG'),
            $this->linha('Material B', 500, 'TB'),
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $linhas */
    protected function rascunhoDeCredito(Fatura $fatura, array $linhas): NotaCreditoDebito
    {
        return app(NotaCreditoDebitoService::class)->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', 'Devolução', $linhas, $fatura->id);
    }

    public function test_credito_parcial_herda_o_tipo_da_linha_correspondente(): void
    {
        $rascunho = $this->rascunhoDeCredito($this->faturaMista(), [
            $this->linha('Material B', 200),
            $this->linha('Serviço A', 100),
        ]);

        $this->assertSame(['TB', 'SG'], $rascunho->linhas->pluck('operation_type')->all());
    }

    public function test_tipo_explicito_da_nota_nao_e_substituido(): void
    {
        $rascunho = $this->rascunhoDeCredito($this->faturaMista(), [$this->linha('Material B', 200, 'SG')]);

        $this->assertSame(['SG'], $rascunho->linhas->pluck('operation_type')->all());
    }

    public function test_sem_correspondencia_e_com_origem_mista_fica_por_preencher(): void
    {
        $rascunho = $this->rascunhoDeCredito($this->faturaMista(), [$this->linha('Outro', 100)]);

        $this->assertSame([null], $rascunho->linhas->pluck('operation_type')->all());
    }

    public function test_sem_correspondencia_usa_o_tipo_unico_da_fatura(): void
    {
        $fatura = $this->faturaEmitida([
            $this->linha('Material A', 1000, 'TB'),
            $this->linha('Material B', 500, 'TB'),
        ]);

        $rascunho = $this->rascunhoDeCredito($fatura, [$this->linha('Outro', 100)]);

        $this->assertSame(['TB'], $rascunho->linhas->pluck('operation_type')->all());
    }

    public function test_rascunho_antigo_sem_tipo_herda_ao_emitir(): void
    {
        $fatura = $this->faturaMista();
        $rascunho = $this->rascunhoDeCredito($fatura, [$this->linha('Material B', 200)]);

        // Simula um rascunho criado antes desta regra.
        $rascunho->linhas()->update(['operation_type' => null]);

        $nota = app(NotaCreditoDebitoService::class)->emitir($rascunho);

        $this->assertSame('emitida', $nota->estado);
        $this->assertSame(['TB'], $nota->linhas->pluck('operation_type')->all());
    }
}
