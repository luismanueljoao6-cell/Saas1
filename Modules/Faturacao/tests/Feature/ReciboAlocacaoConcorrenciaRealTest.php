<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NumeracaoService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * Alocações de recibos sob concorrência real (processos independentes, MySQL): a mesma chave nunca
 * duplica e várias alocações a disputar a mesma linha nunca a sobre-alocam.
 * Sem RefreshDatabase de propósito: os dados têm de estar comprometidos para os outros processos
 * os verem (ver ReenvioCampanhaConcorrenciaRealTest).
 */
#[Group('concorrencia-real')]
class ReciboAlocacaoConcorrenciaRealTest extends TestCase
{
    use ProcessosConcorrentes;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();

        $this->tmp = sys_get_temp_dir().'/alocacao-conc-'.uniqid();
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
        config(['faturacao.chave_privada_path' => $this->tmp.'/chave.pem', 'faturacao.agt.ativo' => false]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Alocações Concorrentes '.uniqid(), 'nif' => (string) random_int(600000000, 699999999)]);
        $this->empresa->aderirServicos([Servico::Faturacao]);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    /** Os processos filhos não herdam o config() deste processo: passa-se por variáveis de ambiente. */
    protected function ambiente(): array
    {
        return [
            'FATURACAO_CHAVE_PRIVADA_PATH' => $this->tmp.'/chave.pem',
            'FATURACAO_AGT_ATIVO' => 'false',
        ];
    }

    /** @return array<int, int> ids das linhas da fatura, pela ordem fiscal */
    protected function fatura(array $precos): array
    {
        $servico = app(FaturaService::class);
        $linhas = [];
        foreach ($precos as $i => $preco) {
            $linhas[] = ['descricao' => 'Serviço '.($i + 1), 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => 14];
        }

        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, $linhas));

        return Fatura::withoutGlobalScopes()->findOrFail($fatura->id)->linhas()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function reciboRg(string $valor): Recibo
    {
        return Recibo::create([
            'empresa_id' => $this->empresa->id,
            'tipo_documento' => 'RG',
            'serie_id' => app(NumeracaoService::class)->obterOuCriarSerie($this->empresa->id, 'RG')->id,
            'cliente_id' => $this->cliente->id,
            'estado' => 'rascunho',
            'valor' => $valor,
            'moeda' => 'AOA',
            'meio_pagamento' => 'dinheiro',
        ]);
    }

    public function test_oito_processos_com_a_mesma_chave_gravam_o_conjunto_uma_so_vez(): void
    {
        [$l1, $l2] = $this->fatura(['1000.00', '500.00']);
        $recibo = $this->reciboRg('1710.00');

        $argumentos = array_fill(0, 8, [
            'empresa_id' => $this->empresa->id,
            'recibo_id' => $recibo->id,
            'chave' => 'chave-conc-igual-001',
            'alocacoes' => [
                ['fatura_linha_id' => $l1, 'liquido_centimos' => 100000, 'imposto_centimos' => 14000],
                ['fatura_linha_id' => $l2, 'liquido_centimos' => 50000, 'imposto_centimos' => 7000],
            ],
        ]);

        $resultados = $this->correrEmParalelo('alocar_recibo', $argumentos, $this->ambiente());

        foreach ($resultados as $r) {
            $this->assertTrue($r['ok'], 'Um processo falhou: '.($r['erro'] ?? '?').' || TODOS: '.json_encode($resultados, JSON_UNESCAPED_UNICODE));
            $this->assertSame(2, $r['linhas']);
            $this->assertSame([1, 2], $r['sequencias']);
        }

        $this->assertCount(1, array_unique(array_map(fn ($r) => json_encode($r['ids']), $resultados)), 'Todos devolvem as mesmas alocações.');
        $this->assertSame(2, DB::table('recibo_alocacoes')->where('recibo_id', $recibo->id)->count());
    }

    public function test_seis_recibos_a_disputar_a_mesma_linha_nunca_a_sobre_alocam(): void
    {
        [$linha] = $this->fatura(['1000.00']);

        $argumentos = [];
        for ($i = 1; $i <= 6; $i++) {
            $recibo = $this->reciboRg('570.00');
            $argumentos[] = [
                'empresa_id' => $this->empresa->id,
                'recibo_id' => $recibo->id,
                'chave' => "chave-conc-disputa-{$i}",
                'alocacoes' => [['fatura_linha_id' => $linha, 'liquido_centimos' => 50000, 'imposto_centimos' => 7000]],
            ];
        }

        $resultados = $this->correrEmParalelo('alocar_recibo', $argumentos, $this->ambiente());

        $aceites = array_filter($resultados, fn ($r) => $r['ok']);
        $recusados = array_filter($resultados, fn ($r) => ! $r['ok']);

        foreach ($recusados as $r) {
            $this->assertStringContainsString('DomainException', $r['erro']);
            $this->assertStringContainsString('excede o líquido ainda disponível', $r['erro'], 'Só pode falhar por falta de disponível (nunca por deadlock).');
        }

        $this->assertCount(2, $aceites, 'A linha de 1000.00 só comporta dois recibos de 500.00 de líquido.');
        $this->assertCount(4, $recusados);

        $this->assertSame(2, DB::table('recibo_alocacoes')->where('fatura_linha_id', $linha)->count());
        $this->assertSame(100000, (int) DB::table('recibo_alocacoes')->where('fatura_linha_id', $linha)->sum('liquido_centimos'));
        $this->assertSame(14000, (int) DB::table('recibo_alocacoes')->where('fatura_linha_id', $linha)->sum('imposto_centimos'));
    }
}
