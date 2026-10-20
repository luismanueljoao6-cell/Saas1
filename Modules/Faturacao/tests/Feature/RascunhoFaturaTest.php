<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AssinaturaFiscalException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Tests\TestCase;

class RascunhoFaturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        config(['faturacao.chave_privada_path' => $caminho]);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));
    }

    protected function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);

        return [$empresa, $cliente];
    }

    protected function linha(float $preco, float $taxa = 14): array
    {
        return [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => $taxa]];
    }

    public function test_atualizar_rascunho_substitui_linhas_e_recalcula_totais(): void
    {
        [$e, $c] = $this->cenario('630000001');
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($e, $c, $this->linha(1000));

        $atualizada = $servico->atualizarRascunho($rascunho, $c, $this->linha(2000), 'Nota nova');

        $this->assertSame('rascunho', $atualizada->estado);
        $this->assertSame('Nota nova', $atualizada->observacoes);
        $this->assertCount(1, $atualizada->linhas);
        $this->assertSame('2000.00', $atualizada->valor_sem_iva);
        $this->assertSame('2280.00', $atualizada->valor_total);
    }

    public function test_atualizar_recusa_fatura_ja_emitida(): void
    {
        [$e, $c] = $this->cenario('630000002');
        $servico = app(FaturaService::class);
        $emitida = $servico->emitir($servico->criarRascunho($e, $c, $this->linha(1000)));

        try {
            $servico->atualizarRascunho($emitida, $c, $this->linha(1), null);
            $this->fail('Uma fatura emitida não pode ser editada.');
        } catch (\DomainException) {
            // esperado
        }

        $this->assertSame('1140.00', Fatura::withoutGlobalScopes()->find($emitida->id)->valor_total);
    }

    public function test_atualizar_recusa_cliente_de_outra_empresa(): void
    {
        [$e, $c] = $this->cenario('630000003');
        [, $clienteDeOutra] = $this->cenario('630000004');
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($e, $c, $this->linha(1000));

        $this->expectException(\DomainException::class);
        $servico->atualizarRascunho($rascunho, $clienteDeOutra, $this->linha(1000));
    }

    public function test_atualizar_aplica_as_mesmas_validacoes_da_criacao(): void
    {
        [$e, $c] = $this->cenario('630000005');
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($e, $c, $this->linha(1000));

        $this->expectException(\DomainException::class);
        $servico->atualizarRascunho($rascunho, $c, $this->linha(1000, 99)); // taxa não permitida
    }

    public function test_eliminar_rascunho_remove_fatura_e_linhas(): void
    {
        [$e, $c] = $this->cenario('630000006');
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($e, $c, $this->linha(1000));

        $servico->eliminarRascunho($rascunho);

        $this->assertDatabaseMissing('faturas', ['id' => $rascunho->id]);
        $this->assertDatabaseMissing('fatura_linhas', ['fatura_id' => $rascunho->id]);
    }

    public function test_eliminar_recusa_fatura_emitida(): void
    {
        [$e, $c] = $this->cenario('630000007');
        $servico = app(FaturaService::class);
        $emitida = $servico->emitir($servico->criarRascunho($e, $c, $this->linha(1000)));

        try {
            $servico->eliminarRascunho($emitida);
            $this->fail('Uma fatura emitida não pode ser apagada.');
        } catch (\DomainException) {
            // esperado
        }

        $this->assertDatabaseHas('faturas', ['id' => $emitida->id, 'estado' => 'emitida']);
    }

    public function test_anulacao_que_falha_na_emissao_nao_deixa_rascunho_de_nota(): void
    {
        [$e, $c] = $this->cenario('630000008');
        $faturas = app(FaturaService::class);
        $fatura = $faturas->emitir($faturas->criarRascunho($e, $c, $this->linha(1000)));

        // A partir daqui a assinatura falha (chave inexistente): é o que acontece
        // no controller quando a emissão da nota não chega ao fim.
        config(['faturacao.chave_privada_path' => sys_get_temp_dir().'/nao-existe-'.uniqid().'.pem']);
        $notas = app(NotaCreditoDebitoService::class);

        try {
            DB::transaction(fn () => $notas->emitir($notas->criarParaAnularFatura($fatura, 'Erro de faturação')));
            $this->fail('A emissão devia falhar sem chave de assinatura.');
        } catch (AssinaturaFiscalException) {
            // esperado
        }

        $this->assertDatabaseCount('notas_credito_debito', 0);
    }
}
