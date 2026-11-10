<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Tests\TestCase;

/**
 * Integridade ao nível da base de dados dos abatimentos de adiantamentos:
 * mesma empresa, imutabilidade e FK RESTRICT. Corre em SQLite e em MySQL (CI).
 */
class IntegridadeMigracoesTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresaA;

    protected Empresa $empresaB;

    /** @var array<int, Fatura> */
    protected array $faturasA = [];

    protected Fatura $faturaB;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        config(['faturacao.chave_privada_path' => $caminho, 'faturacao.agt.ativo' => false]);

        $this->empresaA = $this->criarEmpresa('Loja A', '5001636863');
        $this->empresaB = $this->criarEmpresa('Loja B', '5001636864');

        $clienteA = Cliente::create(['empresa_id' => $this->empresaA->id, 'nome' => 'Cliente A', 'nif' => null]);
        $clienteB = Cliente::create(['empresa_id' => $this->empresaB->id, 'nome' => 'Cliente B', 'nif' => null]);

        $this->faturasA = [$this->rascunho($this->empresaA, $clienteA), $this->rascunho($this->empresaA, $clienteA)];
        $this->faturaB = $this->rascunho($this->empresaB, $clienteB);
    }

    protected function criarEmpresa(string $nome, string $nif): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => $nome, 'nif' => $nif]);
        $empresa->aderirServicos([Servico::Faturacao]);

        return $empresa;
    }

    protected function rascunho(Empresa $empresa, Cliente $cliente): Fatura
    {
        return app(FaturaService::class)->criarRascunho($empresa, $cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]);
    }

    protected function inserir(int $empresaId, int $adiantamentoId, int $finalId, string $chave): void
    {
        DB::table('adiantamento_abatimentos')->insert([
            'empresa_id' => $empresaId,
            'fatura_adiantamento_id' => $adiantamentoId,
            'fatura_final_id' => $finalId,
            'valor_centimos' => 1000,
            'chave' => $chave,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function assertRejeitado(callable $operacao, string $fragmento): void
    {
        try {
            $operacao();
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragmento, $e->getMessage());

            return;
        }

        $this->fail('A base de dados devia ter recusado a operação.');
    }

    public function test_abatimento_entre_faturas_da_mesma_empresa_e_aceite(): void
    {
        $this->inserir($this->empresaA->id, $this->faturasA[0]->id, $this->faturasA[1]->id, 'chave-ok');

        $this->assertSame(1, DB::table('adiantamento_abatimentos')->count());
    }

    public function test_abatimento_nao_liga_faturas_de_outra_empresa(): void
    {
        // Fatura final de outra empresa.
        $this->assertRejeitado(
            fn () => $this->inserir($this->empresaA->id, $this->faturasA[0]->id, $this->faturaB->id, 'chave-cruzada-1'),
            'pertencer a empresa'
        );

        // Empresa indicada diferente da dona das duas faturas.
        $this->assertRejeitado(
            fn () => $this->inserir($this->empresaB->id, $this->faturasA[0]->id, $this->faturasA[1]->id, 'chave-cruzada-2'),
            'pertencer a empresa'
        );

        $this->assertSame(0, DB::table('adiantamento_abatimentos')->count());
    }

    public function test_abatimento_nao_liga_uma_fatura_a_si_propria(): void
    {
        $this->assertRejeitado(
            fn () => $this->inserir($this->empresaA->id, $this->faturasA[0]->id, $this->faturasA[0]->id, 'chave-propria'),
            'documentos diferentes'
        );
    }

    public function test_abatimentos_sao_imutaveis_em_qualquer_driver(): void
    {
        $this->inserir($this->empresaA->id, $this->faturasA[0]->id, $this->faturasA[1]->id, 'chave-imutavel');

        $this->assertRejeitado(
            fn () => DB::table('adiantamento_abatimentos')->update(['valor_centimos' => 1]),
            'imutáveis'
        );
        $this->assertRejeitado(
            fn () => DB::table('adiantamento_abatimentos')->delete(),
            'imutáveis'
        );

        $this->assertSame(1000, (int) DB::table('adiantamento_abatimentos')->value('valor_centimos'));
    }

    public function test_fk_da_empresa_nos_abatimentos_e_restrict(): void
    {
        $fks = collect(Schema::getForeignKeys('adiantamento_abatimentos'))
            ->first(fn (array $fk) => $fk['columns'] === ['empresa_id']);

        $this->assertNotNull($fks);
        $this->assertSame('restrict', strtolower((string) $fks['on_delete']));
    }

    public function test_tabelas_fiscais_nao_se_apagam_em_cascata_com_a_empresa_em_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('A troca CASCADE → RESTRICT só se aplica em MySQL (produção).');
        }

        foreach (['faturas', 'series', 'notas_credito_debito', 'recibos', 'agt_submissoes'] as $tabela) {
            $fk = collect(Schema::getForeignKeys($tabela))->first(fn (array $f) => $f['columns'] === ['empresa_id']);

            $this->assertNotNull($fk, $tabela);
            $this->assertSame('restrict', strtolower((string) $fk['on_delete']), $tabela);
        }
    }
}
