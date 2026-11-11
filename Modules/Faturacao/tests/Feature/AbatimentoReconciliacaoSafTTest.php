<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AbatimentoAdiantamentoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Services\SafTPreValidacao;
use Tests\TestCase;

/**
 * Fase 1 da PR #33 — reconciliação entre abatimentos e documentos na pré-validação do SAF-T,
 * e salvaguarda de produção enquanto a representação fiscal do abatimento não estiver confirmada.
 *
 * Os casos «corrompidos» gravam diretamente na tabela, como faria uma operação fora da aplicação.
 */
class AbatimentoReconciliacaoSafTTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/abat-rec-'.uniqid();
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
            'faturacao.chaves_dir' => $this->tmp,
            'faturacao.chave_privada_path' => $this->tmp.'/chave.pem',
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja A', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente A', 'nif' => null]);
    }

    protected function adiantamentoRecebido(): Fatura
    {
        $faturas = app(FaturaService::class);
        $fa = $faturas->emitir($faturas->criarRascunhoAdiantamento($this->empresa, $this->cliente, [
            ['descricao' => 'Adiantamento', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '1140.00', 'dinheiro', $fa->id);

        return Fatura::withoutGlobalScopes()->findOrFail($fa->id);
    }

    protected function faturaFinal(?Empresa $empresa = null, ?Cliente $cliente = null): Fatura
    {
        $faturas = app(FaturaService::class);
        $ft = $faturas->emitir($faturas->criarRascunho($empresa ?? $this->empresa, $cliente ?? $this->cliente, [
            ['descricao' => 'Venda final', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        return Fatura::withoutGlobalScopes()->findOrFail($ft->id);
    }

    /**
     * Remove o trigger de coerência entre empresas para simular uma base danificada.
     * Só em SQLite: no MySQL o DROP TRIGGER faz commit implícito e quebraria o isolamento.
     */
    protected function semTriggerDeEmpresa(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Só simulável em SQLite; a recusa da base de dados é coberta por outro teste.');
        }

        $triggers = DB::select("select name from sqlite_master where type = 'trigger' and tbl_name = 'adiantamento_abatimentos' and sql like '%pertencer%'");

        $this->assertNotEmpty($triggers, 'O trigger de coerência entre empresas devia existir.');

        foreach ($triggers as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS "'.$trigger->name.'"');
        }
    }

    /** Grava um abatimento diretamente na tabela, sem passar pelo serviço (dados «corrompidos»). */
    protected function abatimentoDireto(int $empresaId, int $faId, int $ftId, int $centimos, string $chave): void
    {
        DB::table('adiantamento_abatimentos')->insert([
            'empresa_id' => $empresaId,
            'fatura_adiantamento_id' => $faId,
            'fatura_final_id' => $ftId,
            'valor_centimos' => $centimos,
            'chave' => $chave,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    protected function validar(): array
    {
        return app(SafTPreValidacao::class)->validarAbatimentos($this->empresa, now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString());
    }

    /** @param  list<string>  $erros */
    protected function contem(array $erros, string $trecho): bool
    {
        return count(array_filter($erros, fn ($e) => str_contains($e, $trecho))) > 0;
    }

    public function test_abatimento_consistente_reconcilia_sem_erros(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        app(AbatimentoAdiantamentoService::class)->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-rec-0001');

        $this->assertSame([], $this->validar());
        $this->assertSame([], app(SafTPreValidacao::class)->validar($this->empresa, now()->startOfYear(), now()->endOfYear()));
    }

    public function test_periodo_sem_abatimentos_nao_gera_erros(): void
    {
        $this->adiantamentoRecebido();

        $this->assertSame([], $this->validar());
    }

    public function test_abatimento_acima_do_recebido_e_do_total_da_fatura_final_e_detetado(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        $this->abatimentoDireto($this->empresa->id, $fa->id, $ft->id, 200000, 'chave-rec-0002');

        $erros = $this->validar();

        $this->assertTrue($this->contem($erros, 'o total abatido'), implode("\n", $erros));
        $this->assertTrue($this->contem($erros, 'Adiantamento '), implode("\n", $erros));
        $this->assertTrue($this->contem($erros, 'Fatura final '), implode("\n", $erros));
    }

    public function test_abatimento_entre_clientes_diferentes_e_detetado(): void
    {
        $fa = $this->adiantamentoRecebido();
        $outro = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Outro cliente', 'nif' => null]);
        $ft = $this->faturaFinal($this->empresa, $outro);
        $this->abatimentoDireto($this->empresa->id, $fa->id, $ft->id, 10000, 'chave-rec-0003');

        $this->assertTrue($this->contem($this->validar(), 'clientes diferentes'));
    }

    public function test_abatimento_que_liga_faturas_de_outra_empresa_e_detetado(): void
    {
        $empresaB = Empresa::create(['nome_comercial' => 'Loja B', 'nif' => '5001636864']);
        $clienteB = Cliente::create(['empresa_id' => $empresaB->id, 'nome' => 'Cliente B', 'nif' => null]);

        $fa = $this->adiantamentoRecebido();
        $ftB = $this->faturaFinal($empresaB, $clienteB);
        // A base de dados já recusa este dado (ver o teste seguinte). Aqui simula-se uma base
        // danificada para provar que a pré-validação SAF-T o deteta como segunda linha de defesa.
        $this->semTriggerDeEmpresa();
        $this->abatimentoDireto($this->empresa->id, $fa->id, $ftB->id, 10000, 'chave-rec-0004');

        $this->assertTrue($this->contem($this->validar(), 'não existe nesta empresa'));
    }

    public function test_a_base_de_dados_recusa_um_abatimento_entre_empresas(): void
    {
        $empresaB = Empresa::create(['nome_comercial' => 'Loja B', 'nif' => '5001636864']);
        $clienteB = Cliente::create(['empresa_id' => $empresaB->id, 'nome' => 'Cliente B', 'nif' => null]);

        $fa = $this->adiantamentoRecebido();
        $ftB = $this->faturaFinal($empresaB, $clienteB);

        $this->expectException(QueryException::class);

        $this->abatimentoDireto($this->empresa->id, $fa->id, $ftB->id, 10000, 'chave-rec-0007');
    }

    public function test_abatimento_com_documento_do_tipo_errado_e_detetado(): void
    {
        $fa = $this->adiantamentoRecebido();
        $outroFa = $this->adiantamentoRecebido();
        $this->abatimentoDireto($this->empresa->id, $fa->id, $outroFa->id, 10000, 'chave-rec-0005');

        $this->assertTrue($this->contem($this->validar(), 'não é uma fatura (FT)'));
    }

    public function test_em_producao_o_saft_recusa_abatimentos_ate_a_representacao_fiscal_ser_confirmada(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        app(AbatimentoAdiantamentoService::class)->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-rec-0006');

        // Fora de produção nada bloqueia.
        $this->assertSame([], $this->validar());

        $this->app['env'] = 'production';

        $erros = $this->validar();
        $this->assertTrue($this->contem($erros, 'representação fiscal'), implode("\n", $erros));

        config(['faturacao.abatimentos_adiantamento_confirmados' => true]);
        $this->assertSame([], $this->validar());
    }
}
