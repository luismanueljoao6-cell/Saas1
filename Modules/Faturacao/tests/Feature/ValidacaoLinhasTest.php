<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Tests\TestCase;

class ValidacaoLinhasTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja A', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente A', 'nif' => null]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function linha(array $extra = []): array
    {
        return [array_merge(['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14], $extra)];
    }

    protected function produtoDe(Empresa $empresa): Produto
    {
        return Produto::create([
            'empresa_id' => $empresa->id,
            'codigo' => 'P-'.uniqid(),
            'nome' => 'Produto',
            'unidade' => 'UN',
            'preco_unitario' => '100.00',
            'taxa_iva' => 14,
            'ativo' => true,
        ]);
    }

    public function test_taxa_nao_permitida_e_recusada_no_servico(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não é permitida');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['taxa_iva' => 5]));
    }

    public function test_dados_invalidos_sao_recusados_no_servico_com_o_numero_da_linha(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Linha 1');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['quantidade' => -2]));
    }

    public function test_produto_de_outra_empresa_e_recusado(): void
    {
        $outra = Empresa::create(['nome_comercial' => 'Loja B', 'nif' => '5001636864']);
        $produto = $this->produtoDe($outra);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não pertence a esta empresa');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['produto_id' => $produto->id]));
    }

    public function test_produto_da_propria_empresa_e_aceite(): void
    {
        $produto = $this->produtoDe($this->empresa);

        $fatura = app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['produto_id' => $produto->id]));

        $this->assertSame('rascunho', $fatura->estado);
    }

    public function test_com_agt_ativa_taxa_sem_codigo_agt_e_recusada_logo_na_criacao(): void
    {
        config([
            'faturacao.agt.ativo' => true,
            'faturacao.taxas_iva_permitidas' => [14.0, 5.0],
            'faturacao.agt.codigos_taxa' => ['14.00' => 'NOR'],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('sem código AGT');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['taxa_iva' => 5]));
    }

    public function test_recibo_de_adiantamento_e_recusado_com_agt_ativa(): void
    {
        config(['faturacao.agt.ativo' => true]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('adiantamento');

        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '100.00', 'dinheiro');
    }
}
