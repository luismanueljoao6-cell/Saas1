<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Tests\TestCase;

class ImutabilidadeLinhasTest extends TestCase
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

    protected function faturaEmitida(): Fatura
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja Imutável', 'nif' => '700000001']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);
        $s = app(FaturaService::class);

        return $s->emitir($s->criarRascunho($empresa, $cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14],
        ]));
    }

    public function test_linha_de_fatura_emitida_nao_pode_ser_alterada(): void
    {
        $f = $this->faturaEmitida();
        $this->expectException(QueryException::class);
        DB::table('fatura_linhas')->where('fatura_id', $f->id)->update(['descricao' => 'adulterada']);
    }

    public function test_linha_de_fatura_emitida_nao_pode_ser_apagada(): void
    {
        $f = $this->faturaEmitida();
        $this->expectException(QueryException::class);
        DB::table('fatura_linhas')->where('fatura_id', $f->id)->delete();
    }

    public function test_nao_se_podem_acrescentar_linhas_a_fatura_emitida(): void
    {
        $f = $this->faturaEmitida();
        $this->expectException(QueryException::class);
        DB::table('fatura_linhas')->insert([
            'fatura_id' => $f->id, 'descricao' => 'extra', 'quantidade' => 1,
            'preco_unitario' => 1, 'taxa_iva' => 14, 'valor_sem_iva' => 1, 'valor_iva' => 0.14, 'valor_total' => 1.14,
        ]);
    }

    public function test_linhas_de_nota_emitida_sao_imutaveis(): void
    {
        $f = $this->faturaEmitida();
        $servico = app(NotaCreditoDebitoService::class);
        $nota = $servico->emitir($servico->criarParaAnularFatura($f, 'Erro'));

        $this->expectException(QueryException::class);
        DB::table('notas_credito_debito_linhas')->where('nota_credito_debito_id', $nota->id)->update(['descricao' => 'x']);
    }

    public function test_numeracao_da_serie_nao_pode_retroceder(): void
    {
        $f = $this->faturaEmitida();
        $this->expectException(QueryException::class);
        DB::table('series')->where('id', $f->serie_id)->update(['ultimo_numero' => 0]);
    }
}
