<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\ReciboService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * Limites financeiros sob concorrência REAL (processos independentes, MySQL).
 *
 * Os serviços bloqueiam o documento (lockForUpdate) e depois somam o que já foi recebido,
 * creditado ou abatido. Em MySQL com REPEATABLE READ essa soma usava o instantâneo aberto antes de
 * esperar pelo lock e não via o que outra transação acabara de confirmar: com a ligação em
 * READ COMMITTED (config/database.php) o limite é respeitado. Medido antes da correção, com 6
 * processos: recibos 6/6 aceites (6840.00 numa fatura de 1140.00), abatimentos 4 a 5 de 6 aceites
 * consoante a corrida (2000.00 a 2500.00 num adiantamento de 1140.00) e notas de crédito 6/6
 * aceites. Estes testes falham se alguém repuser o isolamento REPEATABLE READ
 * (DB_ISOLATION_LEVEL='REPEATABLE READ').
 *
 * Sem RefreshDatabase de propósito: os dados têm de estar comprometidos para os outros processos
 * os verem (ver ReenvioCampanhaConcorrenciaRealTest).
 */
#[Group('concorrencia-real')]
class ConcorrenciaFinanceiraRealTest extends TestCase
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

        $this->tmp = sys_get_temp_dir().'/conc-financeira-'.uniqid();
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

        $this->empresa = Empresa::create(['nome_comercial' => 'Concorrência Financeira '.uniqid(), 'nif' => (string) random_int(700000000, 799999999)]);
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

    /** @return array<int, array<string, mixed>> */
    protected function linha(string $preco, string $descricao = 'Serviço'): array
    {
        return [['descricao' => $descricao, 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => 14]];
    }

    protected function faturaEmitida(string $preco): Fatura
    {
        $servico = app(FaturaService::class);

        return $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, $this->linha($preco)));
    }

    /** @param  array<int, array<string, mixed>>  $resultados */
    protected function aceites(array $resultados): int
    {
        return count(array_filter($resultados, fn ($r) => $r['ok']));
    }

    /** @param  array<int, array<string, mixed>>  $resultados */
    protected function assertRecusasPorLimite(array $resultados, string $fragmento): void
    {
        foreach ($resultados as $r) {
            if ($r['ok']) {
                continue;
            }

            $this->assertStringContainsString('DomainException', $r['erro']);
            $this->assertStringContainsString($fragmento, $r['erro'], 'Só pode falhar por limite (nunca por deadlock ou erro técnico): '.$r['erro']);
        }
    }

    public function test_seis_recibos_simultaneos_nao_recebem_mais_do_que_a_fatura(): void
    {
        $fatura = $this->faturaEmitida('1000.00'); // total 1140.00

        $argumentos = array_fill(0, 6, [
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'valor' => '1140.00',
            'meio' => 'dinheiro',
            'fatura_id' => $fatura->id,
        ]);

        $resultados = $this->correrEmParalelo('emitir_recibo', $argumentos, $this->ambiente());

        $this->assertSame(1, $this->aceites($resultados), 'Só um recibo de 1140.00 cabe numa fatura de 1140.00.');
        $this->assertRecusasPorLimite($resultados, 'excede o valor em dívida');
        $this->assertSame(114000, (int) round(((float) DB::table('recibos')->where('fatura_id', $fatura->id)->where('estado', 'emitido')->sum('valor')) * 100));
    }

    public function test_abatimentos_simultaneos_nao_abatem_mais_do_que_o_adiantamento_recebido(): void
    {
        $servico = app(FaturaService::class);
        $adiantamento = $servico->emitir($servico->criarRascunhoAdiantamento($this->empresa, $this->cliente, $this->linha('1000.00', 'Sinal')));
        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '1140.00', 'dinheiro', $adiantamento->id);
        $final = $this->faturaEmitida('3000.00');

        $argumentos = [];
        for ($i = 1; $i <= 6; $i++) {
            $argumentos[] = [
                'empresa_id' => $this->empresa->id,
                'fa_id' => $adiantamento->id,
                'ft_id' => $final->id,
                'valor' => '500.00',
                'chave' => "abatimento-conc-{$i}-".uniqid(),
            ];
        }

        $resultados = $this->correrEmParalelo('abater', $argumentos, $this->ambiente());

        $this->assertSame(2, $this->aceites($resultados), 'Do adiantamento de 1140.00 só cabem dois abatimentos de 500.00.');
        $this->assertRecusasPorLimite($resultados, 'excede o adiantamento');
        $this->assertSame(100000, (int) DB::table('adiantamento_abatimentos')->where('fatura_adiantamento_id', $adiantamento->id)->sum('valor_centimos'));
    }

    public function test_notas_de_credito_simultaneas_nao_creditam_mais_do_que_a_fatura(): void
    {
        $fatura = $this->faturaEmitida('1000.00'); // total 1140.00
        $notas = app(NotaCreditoDebitoService::class);

        $argumentos = [];
        for ($i = 1; $i <= 6; $i++) {
            $rascunho = $notas->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', "Anulação {$i}", $this->linha('1000.00', 'Correção'), $fatura->id);
            $argumentos[] = ['nota_id' => $rascunho->id];
        }

        $resultados = $this->correrEmParalelo('emitir_nota', $argumentos, $this->ambiente());

        $this->assertSame(1, $this->aceites($resultados), 'Só uma nota de crédito de 1140.00 cabe numa fatura de 1140.00.');
        $this->assertRecusasPorLimite($resultados, 'excede o valor da fatura');
        $this->assertSame(1, DB::table('notas_credito_debito')->where('fatura_id', $fatura->id)->where('tipo', 'credito')->where('estado', 'emitida')->count());
    }
}
