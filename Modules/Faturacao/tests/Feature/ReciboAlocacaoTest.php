<?php

namespace Modules\Faturacao\Tests\Feature;

use DomainException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Models\ReciboAlocacao;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NumeracaoService;
use Modules\Faturacao\Services\ReciboAlocacaoService;
use Modules\Faturacao\Support\Dinheiro;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Alocações de recibos (AR/RG) por linha da fatura de origem: serviço central e integridade na
 * própria base de dados. Corre em SQLite e em MySQL (CI). A concorrência real está em
 * ReciboAlocacaoConcorrenciaRealTest (MySQL, grupo concorrencia-real).
 *
 * Linha de 1000.00 a 14%: líquido 100000, imposto 14000, bruto 114000 (cêntimos).
 */
class ReciboAlocacaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Empresa $outraEmpresa;

    protected Cliente $cliente;

    protected Cliente $outroCliente;

    protected Cliente $clienteDaOutraEmpresa;

    protected ReciboAlocacaoService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        config(['faturacao.chave_privada_path' => $caminho, 'faturacao.agt.ativo' => false]);

        $this->empresa = $this->criarEmpresa('Loja A', '5001636863');
        $this->outraEmpresa = $this->criarEmpresa('Loja B', '5001636864');

        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente A', 'nif' => null]);
        $this->outroCliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente A2', 'nif' => null]);
        $this->clienteDaOutraEmpresa = Cliente::create(['empresa_id' => $this->outraEmpresa->id, 'nome' => 'Cliente B', 'nif' => null]);

        $this->servico = app(ReciboAlocacaoService::class);
    }

    protected function criarEmpresa(string $nome, string $nif): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => $nome, 'nif' => $nif]);
        $empresa->aderirServicos([Servico::Faturacao]);

        return $empresa;
    }

    /** @param  array<int, string>  $precos  preço unitário de cada linha (IVA 14%) */
    protected function faturaEmitida(Empresa $empresa, Cliente $cliente, array $precos = ['1000.00', '500.00']): Fatura
    {
        $servico = app(FaturaService::class);

        return $servico->emitir($this->rascunho($empresa, $cliente, $precos));
    }

    /** @param  array<int, string>  $precos */
    protected function rascunho(Empresa $empresa, Cliente $cliente, array $precos = ['1000.00', '500.00']): Fatura
    {
        $linhas = [];
        foreach ($precos as $i => $preco) {
            $linhas[] = ['descricao' => 'Serviço '.($i + 1), 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => 14];
        }

        return app(FaturaService::class)->criarRascunho($empresa, $cliente, $linhas);
    }

    /** @return array<int, int> ids das linhas pela ordem fiscal (ordem) */
    protected function idsDasLinhas(Fatura $fatura): array
    {
        return Fatura::withoutGlobalScopes()->findOrFail($fatura->id)->linhas()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function recibo(Empresa $empresa, Cliente $cliente, string $valor, string $tipo = 'RG', string $estado = 'rascunho'): Recibo
    {
        return Recibo::create([
            'empresa_id' => $empresa->id,
            'tipo_documento' => $tipo,
            'serie_id' => app(NumeracaoService::class)->obterOuCriarSerie($empresa->id, $tipo)->id,
            'cliente_id' => $cliente->id,
            'estado' => $estado,
            'valor' => $valor,
            'moeda' => 'AOA',
            'meio_pagamento' => 'dinheiro',
        ]);
    }

    /** @return array{fatura_linha_id: int, liquido_centimos: int, imposto_centimos: int} */
    protected function item(int $linhaId, int $liquido, int $imposto): array
    {
        return ['fatura_linha_id' => $linhaId, 'liquido_centimos' => $liquido, 'imposto_centimos' => $imposto];
    }

    /** @param  array<int, array<string, int>>  $itens */
    protected function registar(Recibo $recibo, array $itens, string $chave = 'chave-teste-0001', ?Empresa $empresa = null): array
    {
        return $this->servico->registar(($empresa ?? $this->empresa)->id, $recibo->id, $itens, $chave);
    }

    protected function totalDeAlocacoes(): int
    {
        return DB::table('recibo_alocacoes')->count();
    }

    // ---------------------------------------------------------------- serviço

    public function test_regista_alocacoes_por_linha_com_numero_fiscal_base_1_e_sequencia(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente);
        [$l1, $l2] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1710.00');

        $linhas = $this->registar($recibo, [$this->item($l2, 50000, 7000), $this->item($l1, 100000, 14000)]);

        $this->assertCount(2, $linhas);
        $this->assertSame([$l1, $l2], array_column($linhas, 'fatura_linha_id'));
        $this->assertSame([1, 2], array_column($linhas, 'numero_linha_fiscal'));
        $this->assertSame([1, 2], array_column($linhas, 'sequencia'));
        $this->assertSame([100000, 50000], array_map('intval', array_column($linhas, 'liquido_centimos')));
        $this->assertSame([14000, 7000], array_map('intval', array_column($linhas, 'imposto_centimos')));
        $this->assertSame([114000, 57000], array_map('intval', array_column($linhas, 'bruto_centimos')));
        $this->assertSame($fatura->id, (int) $linhas[0]['fatura_id']);
        $this->assertSame($this->empresa->id, (int) $linhas[0]['empresa_id']);
    }

    public function test_a_soma_dos_brutos_alocados_reconcilia_com_o_valor_do_recibo(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente);
        [$l1, $l2] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1710.00');

        $this->registar($recibo, [$this->item($l1, 100000, 14000), $this->item($l2, 50000, 7000)]);

        $this->assertSame(
            Dinheiro::centimos($recibo->fresh()->valor),
            (int) DB::table('recibo_alocacoes')->where('recibo_id', $recibo->id)->sum('bruto_centimos'),
        );
    }

    public function test_o_numero_fiscal_segue_a_ordem_da_fatura_e_nao_o_id_da_linha(): void
    {
        $rascunho = $this->rascunho($this->empresa, $this->cliente);
        [$primeira, $segunda] = $this->idsDasLinhas($rascunho);

        // A segunda linha criada passa a ser a primeira da fatura (como o builder AGT a numeraria).
        DB::table('fatura_linhas')->where('id', $primeira)->update(['ordem' => 1]);
        DB::table('fatura_linhas')->where('id', $segunda)->update(['ordem' => 0]);
        $fatura = app(FaturaService::class)->emitir($rascunho->fresh());

        $recibo = $this->recibo($this->empresa, $this->cliente, '1710.00');
        $linhas = $this->registar($recibo, [$this->item($primeira, 100000, 14000), $this->item($segunda, 50000, 7000)]);

        $porLinha = array_column($linhas, 'numero_linha_fiscal', 'fatura_linha_id');
        $this->assertSame(2, (int) $porLinha[$primeira]);
        $this->assertSame(1, (int) $porLinha[$segunda]);
        $this->assertSame($segunda, (int) $linhas[0]['fatura_linha_id'], 'A sequência segue a ordem fiscal.');
        $this->assertSame($fatura->id, (int) $linhas[0]['fatura_id']);
    }

    public function test_a_mesma_chave_com_os_mesmos_dados_e_idempotente(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');
        $itens = [$this->item($l1, 100000, 14000)];

        $primeira = $this->registar($recibo, $itens);
        $segunda = $this->registar($recibo, $itens);

        $this->assertSame($primeira, $segunda);
        $this->assertSame(1, $this->totalDeAlocacoes());
    }

    public function test_o_repetir_o_pedido_depois_de_o_recibo_ser_emitido_devolve_o_mesmo_resultado(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');
        $itens = [$this->item($l1, 100000, 14000)];

        $primeira = $this->registar($recibo, $itens);
        DB::table('recibos')->where('id', $recibo->id)->update(['estado' => 'emitido']);

        $this->assertSame($primeira, $this->registar($recibo, $itens));
        $this->assertSame(1, $this->totalDeAlocacoes());
    }

    public function test_a_ordem_das_linhas_no_pedido_nao_altera_a_idempotencia(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente);
        [$l1, $l2] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1710.00');

        $primeira = $this->registar($recibo, [$this->item($l1, 100000, 14000), $this->item($l2, 50000, 7000)]);
        $segunda = $this->registar($recibo, [$this->item($l2, 50000, 7000), $this->item($l1, 100000, 14000)]);

        $this->assertSame($primeira, $segunda);
        $this->assertSame(2, $this->totalDeAlocacoes());
    }

    public function test_chave_reutilizada_com_dados_diferentes_e_recusada(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->registar($recibo, [$this->item($l1, 100000, 14000)]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Chave de idempotência reutilizada com dados diferentes.');
        $this->registar($recibo, [$this->item($l1, 90000, 14000)]);
    }

    public function test_um_recibo_nao_aceita_um_segundo_conjunto_com_outra_chave(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->registar($recibo, [$this->item($l1, 100000, 14000)], 'chave-teste-0001');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('já tem as suas alocações');
        $this->registar($recibo, [$this->item($l1, 100000, 14000)], 'chave-teste-0002');
    }

    public function test_recusa_sobre_alocacao_acima_do_liquido_da_linha(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.01');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede o líquido ainda disponível');
        $this->registar($recibo, [$this->item($l1, 100001, 14000)]);
    }

    public function test_recusa_sobre_alocacao_acima_do_imposto_da_linha(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.01');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede o imposto ainda disponível');
        $this->registar($recibo, [$this->item($l1, 100000, 14001)]);
    }

    public function test_recusa_quando_a_soma_dos_brutos_difere_do_valor_do_recibo(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1000.00');

        try {
            $this->registar($recibo, [$this->item($l1, 100000, 14000)]);
            $this->fail('Devia recusar: 1140.00 alocados para um recibo de 1000.00.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('tem de ser igual ao valor do recibo', $e->getMessage());
        }

        $this->assertSame(0, $this->totalDeAlocacoes());
    }

    public function test_um_segundo_recibo_so_pode_alocar_o_que_resta_na_linha(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);

        $primeiro = $this->recibo($this->empresa, $this->cliente, '570.00');
        $this->registar($primeiro, [$this->item($l1, 50000, 7000)], 'chave-teste-0001');

        $excedente = $this->recibo($this->empresa, $this->cliente, '700.00');
        try {
            $this->registar($excedente, [$this->item($l1, 60000, 10000)], 'chave-teste-0002');
            $this->fail('Devia recusar: só restam 500.00 de líquido na linha.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('excede o líquido ainda disponível (500.00)', $e->getMessage());
        }

        $segundo = $this->recibo($this->empresa, $this->cliente, '570.00');
        $this->registar($segundo, [$this->item($l1, 50000, 7000)], 'chave-teste-0003');

        $terceiro = $this->recibo($this->empresa, $this->cliente, '1.00');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede o líquido ainda disponível (0.00)');
        $this->registar($terceiro, [$this->item($l1, 100, 0)], 'chave-teste-0004');
    }

    public function test_um_recibo_anulado_liberta_o_disponivel_da_linha(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);

        $primeiro = $this->recibo($this->empresa, $this->cliente, '1140.00');
        $this->registar($primeiro, [$this->item($l1, 100000, 14000)], 'chave-teste-0001');
        DB::table('recibos')->where('id', $primeiro->id)->update(['estado' => 'anulado']);

        $segundo = $this->recibo($this->empresa, $this->cliente, '1140.00');
        $this->registar($segundo, [$this->item($l1, 100000, 14000)], 'chave-teste-0002');

        $this->assertSame(2, $this->totalDeAlocacoes());
    }

    public function test_tudo_ou_nada_uma_linha_invalida_nao_deixa_alocacoes_parciais(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente);
        [$l1, $l2] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1710.00');

        try {
            $this->registar($recibo, [$this->item($l1, 100000, 14000), $this->item($l2, 50001, 7000)]);
            $this->fail('A segunda linha excede o disponível.');
        } catch (DomainException) {
            // esperado
        }

        $this->assertSame(0, $this->totalDeAlocacoes());
    }

    public function test_recusa_recibo_que_ja_nao_e_rascunho(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00', 'RG', 'emitido');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('enquanto o recibo é rascunho');
        $this->registar($recibo, [$this->item($l1, 100000, 14000)]);
    }

    public function test_recusa_recibos_que_nao_levam_payment_receipt(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00', 'RC');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Só os recibos AR e RG');
        $this->registar($recibo, [$this->item($l1, 100000, 14000)]);
    }

    public function test_recusa_linhas_de_outra_empresa(): void
    {
        $alheia = $this->faturaEmitida($this->outraEmpresa, $this->clienteDaOutraEmpresa, ['1000.00']);
        [$linhaAlheia] = $this->idsDasLinhas($alheia);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        try {
            $this->registar($recibo, [$this->item($linhaAlheia, 100000, 14000)]);
            $this->fail('Uma linha de outra empresa nunca pode ser alocada.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('pertencer a faturas desta empresa', $e->getMessage());
        }

        $this->assertSame(0, $this->totalDeAlocacoes());
    }

    public function test_recusa_recibo_de_outra_empresa(): void
    {
        $fatura = $this->faturaEmitida($this->outraEmpresa, $this->clienteDaOutraEmpresa, ['1000.00']);
        [$linha] = $this->idsDasLinhas($fatura);
        $reciboAlheio = $this->recibo($this->outraEmpresa, $this->clienteDaOutraEmpresa, '1140.00');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('O recibo tem de existir nesta empresa.');
        $this->servico->registar($this->empresa->id, $reciboAlheio->id, [$this->item($linha, 100000, 14000)], 'chave-teste-0001');
    }

    public function test_recusa_faturas_de_outro_cliente(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->outroCliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mesmo cliente');
        $this->registar($recibo, [$this->item($l1, 100000, 14000)]);
    }

    public function test_recusa_faturas_em_rascunho(): void
    {
        $rascunho = $this->rascunho($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($rascunho);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tem de estar emitida');
        $this->registar($recibo, [$this->item($l1, 100000, 14000)]);
    }

    public function test_recusa_linhas_inexistentes(): void
    {
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('existir e pertencer a faturas desta empresa');
        $this->registar($recibo, [$this->item(999999, 100000, 14000)]);
    }

    /** @return array<string, array{0: array<int, mixed>}> */
    public static function pedidosInvalidos(): array
    {
        return [
            'lista vazia' => [[]],
            'liquido float' => [[['fatura_linha_id' => 1, 'liquido_centimos' => 100.5, 'imposto_centimos' => 14000]]],
            'liquido string' => [[['fatura_linha_id' => 1, 'liquido_centimos' => '100000', 'imposto_centimos' => 14000]]],
            'imposto em falta' => [[['fatura_linha_id' => 1, 'liquido_centimos' => 100000]]],
            'valor negativo' => [[['fatura_linha_id' => 1, 'liquido_centimos' => -1, 'imposto_centimos' => 14000]]],
            'tudo a zero' => [[['fatura_linha_id' => 1, 'liquido_centimos' => 0, 'imposto_centimos' => 0]]],
            'linha invalida' => [[['fatura_linha_id' => 0, 'liquido_centimos' => 1, 'imposto_centimos' => 0]]],
            'linha repetida' => [[
                ['fatura_linha_id' => 1, 'liquido_centimos' => 1, 'imposto_centimos' => 0],
                ['fatura_linha_id' => 1, 'liquido_centimos' => 2, 'imposto_centimos' => 0],
            ]],
        ];
    }

    /** @param  array<int, mixed>  $pedido */
    #[DataProvider('pedidosInvalidos')]
    public function test_recusa_pedidos_mal_formados_sem_tocar_na_base_de_dados(array $pedido): void
    {
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        try {
            $this->servico->registar($this->empresa->id, $recibo->id, $pedido, 'chave-teste-0001');
            $this->fail('Devia recusar o pedido.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $this->assertSame(0, $this->totalDeAlocacoes());
    }

    public function test_recusa_chave_de_idempotencia_invalida(): void
    {
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chave de idempotência inválida');
        $this->servico->registar($this->empresa->id, $recibo->id, [$this->item(1, 1, 0)], 'curta');
    }

    // ------------------------------------------------- integridade na base de dados

    /** @return array<string, mixed> uma linha válida, para os testes de integridade a editarem */
    protected function linhaCrua(Fatura $fatura, int $linhaId, Recibo $recibo, array $alterar = []): array
    {
        return array_merge([
            'empresa_id' => $recibo->empresa_id,
            'recibo_id' => $recibo->id,
            'fatura_id' => $fatura->id,
            'fatura_linha_id' => $linhaId,
            'numero_linha_fiscal' => 1,
            'sequencia' => 1,
            'liquido_centimos' => 100000,
            'imposto_centimos' => 14000,
            'bruto_centimos' => 114000,
            'chave' => 'chave-direta-001',
            'created_at' => now(),
            'updated_at' => now(),
        ], $alterar);
    }

    protected function assertBaseRecusa(callable $operacao, string $fragmento = ''): void
    {
        try {
            $operacao();
        } catch (QueryException $e) {
            $this->assertInstanceOf(QueryException::class, $e);

            if ($fragmento !== '') {
                $this->assertStringContainsString($fragmento, $e->getMessage());
            }

            return;
        }

        $this->fail('A base de dados devia ter recusado a operação.');
    }

    public function test_a_base_de_dados_aceita_uma_alocacao_coerente(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        DB::table('recibo_alocacoes')->insert($this->linhaCrua($fatura, $l1, $recibo));

        $this->assertSame(1, $this->totalDeAlocacoes());
    }

    public function test_a_base_de_dados_recusa_alterar_ou_apagar_alocacoes(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');
        DB::table('recibo_alocacoes')->insert($this->linhaCrua($fatura, $l1, $recibo));

        $this->assertBaseRecusa(fn () => DB::table('recibo_alocacoes')->update(['liquido_centimos' => 1]), 'imutáveis');
        $this->assertBaseRecusa(fn () => DB::table('recibo_alocacoes')->delete(), 'imutáveis');
        $this->assertSame(100000, (int) DB::table('recibo_alocacoes')->value('liquido_centimos'));
    }

    public function test_o_modelo_recusa_alterar_ou_apagar_alocacoes(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');
        $this->registar($recibo, [$this->item($l1, 100000, 14000)]);

        app(TenantManager::class)->set($this->empresa->id);
        $alocacao = ReciboAlocacao::query()->firstOrFail();

        $alocacao->liquido_centimos = 1;
        try {
            $alocacao->save();
            $this->fail('O modelo devia recusar a alteração.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('imutáveis', $e->getMessage());
        }

        try {
            $alocacao->delete();
            $this->fail('O modelo devia recusar a eliminação.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('imutáveis', $e->getMessage());
        }

        $this->assertSame(100000, (int) DB::table('recibo_alocacoes')->value('liquido_centimos'));
    }

    public function test_o_modelo_nao_cria_alocacoes_por_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);
        ReciboAlocacao::create(['empresa_id' => $this->empresa->id, 'liquido_centimos' => 1]);
    }

    public function test_o_modelo_so_le_alocacoes_da_empresa_do_tenant(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $this->registar($this->recibo($this->empresa, $this->cliente, '1140.00'), [$this->item($l1, 100000, 14000)]);

        app(TenantManager::class)->set($this->outraEmpresa->id);
        $this->assertSame(0, ReciboAlocacao::query()->count());

        app(TenantManager::class)->set($this->empresa->id);
        $this->assertSame(1, ReciboAlocacao::query()->count());
    }

    public function test_a_base_de_dados_recusa_empresa_diferente_da_do_recibo(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->assertBaseRecusa(fn () => DB::table('recibo_alocacoes')->insert(
            $this->linhaCrua($fatura, $l1, $recibo, ['empresa_id' => $this->outraEmpresa->id]),
        ));
        $this->assertSame(0, $this->totalDeAlocacoes());
    }

    public function test_a_base_de_dados_recusa_uma_linha_que_nao_pertence_a_fatura_indicada(): void
    {
        $f1 = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        $f2 = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$linhaDeF1] = $this->idsDasLinhas($f1);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->assertBaseRecusa(fn () => DB::table('recibo_alocacoes')->insert(
            $this->linhaCrua($f2, $linhaDeF1, $recibo),
        ));
    }

    public function test_a_base_de_dados_recusa_bruto_incoerente(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->assertBaseRecusa(fn () => DB::table('recibo_alocacoes')->insert(
            $this->linhaCrua($fatura, $l1, $recibo, ['bruto_centimos' => 114001]),
        ), 'bruto da alocação');
    }

    public function test_a_base_de_dados_recusa_numeracao_fiscal_a_zero(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');

        $this->assertBaseRecusa(fn () => DB::table('recibo_alocacoes')->insert(
            $this->linhaCrua($fatura, $l1, $recibo, ['numero_linha_fiscal' => 0]),
        ), 'começa em 1');
    }

    public function test_a_base_de_dados_recusa_alocacoes_para_recibos_ja_emitidos(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $emitido = $this->recibo($this->empresa, $this->cliente, '1140.00', 'RG', 'emitido');

        $this->assertBaseRecusa(fn () => DB::table('recibo_alocacoes')->insert(
            $this->linhaCrua($fatura, $l1, $emitido),
        ), 'enquanto o recibo é rascunho');
    }

    public function test_a_base_de_dados_recusa_a_mesma_linha_duas_vezes_no_mesmo_recibo(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');
        DB::table('recibo_alocacoes')->insert($this->linhaCrua($fatura, $l1, $recibo));

        $this->assertBaseRecusa(fn () => DB::table('recibo_alocacoes')->insert(
            $this->linhaCrua($fatura, $l1, $recibo, ['sequencia' => 2, 'chave' => 'chave-direta-002']),
        ));
    }

    public function test_a_base_de_dados_nao_deixa_eliminar_a_fatura_ou_o_recibo_com_alocacoes(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $recibo = $this->recibo($this->empresa, $this->cliente, '1140.00');
        $this->registar($recibo, [$this->item($l1, 100000, 14000)]);

        $this->assertBaseRecusa(fn () => DB::table('fatura_linhas')->where('id', $l1)->delete());
        $this->assertBaseRecusa(fn () => DB::table('recibos')->where('id', $recibo->id)->delete());
        $this->assertSame(1, $this->totalDeAlocacoes());
    }

    public function test_o_rollback_da_migration_e_recusado_quando_ha_alocacoes(): void
    {
        $fatura = $this->faturaEmitida($this->empresa, $this->cliente, ['1000.00']);
        [$l1] = $this->idsDasLinhas($fatura);
        $this->registar($this->recibo($this->empresa, $this->cliente, '1140.00'), [$this->item($l1, 100000, 14000)]);

        $migration = require base_path('Modules/Faturacao/database/migrations/2026_11_18_000001_create_recibo_alocacoes_table.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback recusado');
        $migration->down();
    }
}
