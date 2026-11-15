<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Tests\TestCase;

/** Art. 8.º n.º 5 do RJF (DP 71/25): a nota de crédito contém a expressão «anulação» ou «rectificação». */
class NotaCreditoExpressaoFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_anulacao_total_rectificacao_parcial_e_debito_sem_expressao(): void
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-expr-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));
        config(['faturacao.chave_privada_path' => $caminho, 'faturacao.agt.ativo' => false]);

        $empresa = Empresa::create(['nome_comercial' => 'Loja Expressão', 'nif' => '5001636863']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);

        $faturas = app(FaturaService::class);
        $notas = app(NotaCreditoDebitoService::class);
        $linhas = [['descricao' => 'Serviço', 'quantidade' => '2', 'preco_unitario' => '100.00', 'taxa_iva' => '14']];

        $fatura = $faturas->emitir($faturas->criarRascunho($empresa, $cliente, $linhas));
        $parcial = $notas->criarRascunho($empresa->id, $cliente->id, 'credito', 'Devolução de uma unidade', [
            ['descricao' => 'Serviço', 'quantidade' => '1', 'preco_unitario' => '100.00', 'taxa_iva' => '14'],
        ], $fatura->id);
        $this->assertSame('Rectificação', $parcial->expressaoFiscal());

        $outra = $faturas->emitir($faturas->criarRascunho($empresa, $cliente, $linhas));
        $total = $notas->criarParaAnularFatura($outra, 'Erro de faturação');
        $this->assertSame('Anulação', $total->expressaoFiscal());

        $debito = $notas->criarRascunho($empresa->id, $cliente->id, 'debito', 'Repasse de despesas', [
            ['descricao' => 'Despesa', 'quantidade' => '1', 'preco_unitario' => '10.00', 'taxa_iva' => '0'],
        ]);
        $this->assertNull($debito->expressaoFiscal());
    }
}
