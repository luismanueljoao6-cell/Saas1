<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AbatimentoAdiantamentoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\ReciboService;
use Tests\TestCase;

/** Notas de crédito sobre adiantamentos (FA) já abatidos em faturas finais (FT). */
class AdiantamentoNotaCreditoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/adiant-nc-'.uniqid();
        mkdir($this->tmp);
        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->tmp.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmp);
        });

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($this->tmp.'/chave.pem', $pem);

        config([
            'faturacao.chave_privada_path' => $this->tmp.'/chave.pem',
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja FA', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    /** FA de 1140.00 (1000 + 14%), emitido e totalmente recebido. */
    protected function adiantamentoRecebido(): Fatura
    {
        $faturas = app(FaturaService::class);
        $fa = $faturas->emitir($faturas->criarRascunhoAdiantamento($this->empresa, $this->cliente, [
            ['descricao' => 'Adiantamento', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '1140.00', 'dinheiro', $fa->id);

        return Fatura::withoutGlobalScopes()->findOrFail($fa->id);
    }

    protected function faturaFinal(): Fatura
    {
        $faturas = app(FaturaService::class);
        $ft = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Venda final', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        return Fatura::withoutGlobalScopes()->findOrFail($ft->id);
    }

    protected function abater(Fatura $fa, Fatura $ft, string $valor): void
    {
        app(AbatimentoAdiantamentoService::class)->abater($this->empresa->id, $fa->id, $ft->id, $valor, 'chave-teste-'.uniqid());
    }

    /** @return list<array<string, mixed>> */
    protected function linhaNc(string $preco): array
    {
        return [['descricao' => 'Correção', 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => 14]];
    }


    public function test_operacoes_sobre_notas_recusam_empresa_fora_do_tenant_ativo(): void
    {
        $fatura = $this->faturaFinal();
        $notas = app(NotaCreditoDebitoService::class);
        $rascunho = $notas->criarRascunho(
            $this->empresa->id,
            $this->cliente->id,
            'credito',
            'Ajuste',
            $this->linhaNc('10.00'),
            $fatura->id,
        );

        $tenant = app(TenantManager::class);
        $tenant->set((int) $this->empresa->id + 1000);

        try {
            $this->expectException(\\DomainException::class);
            $this->expectExceptionMessage('A operação não pertence à empresa do contexto atual.');
            $notas->eliminarRascunho($rascunho);
        } finally {
            $tenant->clear();
        }
    }

    public function test_anulacao_total_de_adiantamento_abatido_e_recusada(): void
    {
        $fa = $this->adiantamentoRecebido();
        $this->abater($fa, $this->faturaFinal(), '500.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('anulação total não é possível');

        app(NotaCreditoDebitoService::class)->criarParaAnularFatura($fa, 'Erro');
    }

    public function test_credito_parcial_sobre_a_parte_nao_abatida_e_aceite(): void
    {
        $fa = $this->adiantamentoRecebido();
        $this->abater($fa, $this->faturaFinal(), '500.00');

        $notas = app(NotaCreditoDebitoService::class);
        $nota = $notas->emitir($notas->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', 'Ajuste', $this->linhaNc('100.00'), $fa->id));

        $this->assertSame('emitida', $nota->estado);
    }

    public function test_credito_que_invade_a_parte_abatida_e_recusado(): void
    {
        $fa = $this->adiantamentoRecebido();
        $this->abater($fa, $this->faturaFinal(), '500.00');

        $notas = app(NotaCreditoDebitoService::class);
        $rascunho = $notas->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', 'Ajuste', $this->linhaNc('600.00'), $fa->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('parte já abatida');

        $notas->emitir($rascunho);
    }

    public function test_adiantamento_anulado_nao_pode_ser_abatido(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();

        $notas = app(NotaCreditoDebitoService::class);
        $notas->emitir($notas->criarParaAnularFatura($fa, 'Anulação'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('excede o adiantamento');

        $this->abater($fa, $ft, '100.00');
    }
}
