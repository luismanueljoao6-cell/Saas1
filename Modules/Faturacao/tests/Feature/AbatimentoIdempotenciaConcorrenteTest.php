<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AbatimentoAdiantamentoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Tests\TestCase;

/**
 * Fase 3 da PR #33 — idempotência do abatimento sob concorrência.
 *
 * A corrida «perdida» é simulada: imediatamente antes do INSERT do serviço, outra
 * «requisição» grava a mesma chave. Prova o caminho de recuperação (violação da chave
 * única -> devolve o original, ou rejeita se os dados diferirem). Não substitui um ensaio
 * com processos paralelos reais em MySQL.
 */
class AbatimentoIdempotenciaConcorrenteTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/abat-idem-'.uniqid();
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

    /** FA de 1140.00 emitido e totalmente recebido. */
    protected function adiantamentoRecebido(): Fatura
    {
        $faturas = app(FaturaService::class);
        $fa = $faturas->emitir($faturas->criarRascunhoAdiantamento($this->empresa, $this->cliente, [
            ['descricao' => 'Adiantamento', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '1140.00', 'dinheiro', $fa->id);

        return Fatura::withoutGlobalScopes()->findOrFail($fa->id);
    }

    /** FT de 1140.00 emitida. */
    protected function faturaFinal(): Fatura
    {
        $faturas = app(FaturaService::class);
        $ft = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Venda final', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));

        return Fatura::withoutGlobalScopes()->findOrFail($ft->id);
    }

    /**
     * Grava, imediatamente antes do INSERT do serviço, uma linha concorrente com a mesma chave.
     * O guarda evita recursão (o próprio insert do concorrente também passa por aqui).
     */
    protected function concorrenteGravaAntesDoInsert(Fatura $fa, Fatura $ft, int $centimos, string $chave): void
    {
        $conexao = DB::connection();

        if (! method_exists($conexao, 'beforeExecuting')) {
            $this->markTestSkipped('Esta versão do Laravel não tem Connection::beforeExecuting.');
        }

        $feito = false;

        $conexao->beforeExecuting(function (string $query) use (&$feito, $fa, $ft, $centimos, $chave): void {
            if ($feito || ! str_contains($query, 'adiantamento_abatimentos') || ! str_starts_with(strtolower(ltrim($query)), 'insert')) {
                return;
            }

            $feito = true;

            DB::table('adiantamento_abatimentos')->insert([
                'empresa_id' => $this->empresa->id,
                'fatura_adiantamento_id' => $fa->id,
                'fatura_final_id' => $ft->id,
                'valor_centimos' => $centimos,
                'chave' => $chave,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_corrida_perdida_com_os_mesmos_dados_devolve_o_abatimento_original(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        $this->concorrenteGravaAntesDoInsert($fa, $ft, 50000, 'chave-corrida-0001');

        $resultado = app(AbatimentoAdiantamentoService::class)
            ->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-corrida-0001');

        $linhas = DB::table('adiantamento_abatimentos')->where('empresa_id', $this->empresa->id)->get();

        $this->assertCount(1, $linhas, 'A corrida nunca pode duplicar o abatimento.');
        $this->assertSame((int) $linhas->first()->id, (int) $resultado['id']);
        $this->assertSame(50000, (int) $resultado['valor_centimos']);
    }

    public function test_corrida_perdida_com_dados_diferentes_e_rejeitada_sem_duplicar(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        // O concorrente já fez commit (registo existente ANTES da transação do serviço, que por isso
        // sobrevive ao rollback), mas a verificação prévia ainda não o viu: é a janela da corrida.
        DB::table('adiantamento_abatimentos')->insert([
            'empresa_id' => $this->empresa->id,
            'fatura_adiantamento_id' => $fa->id,
            'fatura_final_id' => $ft->id,
            'valor_centimos' => 10000,
            'chave' => 'chave-corrida-0002',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $servico = new class(app(\Modules\Faturacao\Services\ReciboService::class)) extends \Modules\Faturacao\Services\AbatimentoAdiantamentoService
        {
            private bool $primeiraConsulta = true;

            protected function resolverExistente(int $empresaId, string $chave, int $faId, int $ftId, int $centimos): ?array
            {
                if ($this->primeiraConsulta) {
                    $this->primeiraConsulta = false;

                    return null;
                }

                return parent::resolverExistente($empresaId, $chave, $faId, $ftId, $centimos);
            }
        };

        try {
            $servico->abater($this->empresa->id, $fa->id, $ft->id, '500.00', 'chave-corrida-0002');

            $this->fail('Devia ter rejeitado a chave reutilizada com dados diferentes.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Chave de idempotência reutilizada com dados diferentes.', $e->getMessage());
        }

        $linhas = DB::table('adiantamento_abatimentos')->where('empresa_id', $this->empresa->id)->get();

        $this->assertCount(1, $linhas);
        $this->assertSame(10000, (int) $linhas->first()->valor_centimos, 'O registo do concorrente não pode ser alterado.');
    }

    public function test_dois_abatimentos_com_chaves_diferentes_nunca_ultrapassam_o_adiantamento(): void
    {
        $fa = $this->adiantamentoRecebido();
        $ft = $this->faturaFinal();
        $servico = app(AbatimentoAdiantamentoService::class);

        $servico->abater($this->empresa->id, $fa->id, $ft->id, '800.00', 'chave-soma-0001');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('excede o adiantamento');

        $servico->abater($this->empresa->id, $fa->id, $ft->id, '400.00', 'chave-soma-0002');
    }
}
