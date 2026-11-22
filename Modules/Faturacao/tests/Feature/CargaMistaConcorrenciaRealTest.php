<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\ReciboService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PreparaEmpresaConcorrente;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * Carga mista sobre os MESMOS documentos (MySQL, processos reais): recibos, notas de crédito e
 * abatimentos de adiantamentos a competir ao mesmo tempo. Cada tipo de operação já tem o seu teste;
 * aqui procura-se o que só aparece quando se cruzam: deadlocks entre serviços que bloqueiam por
 * ordens diferentes e limites que cada serviço respeita sozinho mas que juntos deixam ultrapassar.
 *
 * Que invariantes valem (e quais NÃO valem):
 *  - recibos + abatimentos nunca excedem o total da fatura (cada um respeita o saldo no seu momento);
 *  - as notas de crédito somam, no máximo, o total da fatura (mais 1 cêntimo por linha de arredondamento);
 *  - uma nota de crédito emitida DEPOIS de recebimentos pode deixar o saldo da fatura negativo: é crédito
 *    a favor do cliente, não um erro, por isso o saldo final só tem de ser >= 0 sem notas de crédito;
 *  - nunca se abate mais do que o adiantamento recebeu, nem se abate e credita a mesma parte do adiantamento;
 *  - as numerações ficam contínuas e toda a recusa é regra de negócio (nunca deadlock, timeout de lock
 *    ou violação de integridade).
 */
#[Group('concorrencia-real')]
class CargaMistaConcorrenciaRealTest extends TestCase
{
    use PreparaEmpresaConcorrente;
    use ProcessosConcorrentes;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();

        $this->prepararEmpresaFiscal('carga-mista');
    }

    /** @return array<int, array<string, mixed>> */
    protected function linha(string $preco, string $descricao = 'Serviço'): array
    {
        return [['descricao' => $descricao, 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => 14]];
    }

    protected function centimos(mixed $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    protected function faturaFinal(string $preco): Fatura
    {
        $faturas = app(FaturaService::class);

        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linha($preco, 'Final')));
    }

    /** Adiantamento (FA) de 5000.00 + 14% = 5700.00, já recebido por inteiro. */
    protected function adiantamentoRecebido(): Fatura
    {
        $faturas = app(FaturaService::class);
        $adiantamento = $faturas->emitir($faturas->criarRascunhoAdiantamento($this->empresa, $this->cliente, $this->linha('5000.00', 'Sinal')));
        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '5700.00', 'dinheiro', $adiantamento->id);

        return $adiantamento;
    }

    /** @return array<string, mixed> */
    protected function reciboParaFatura(Fatura $fatura, string $valor): array
    {
        return ['acao' => 'emitir_recibo', 'args' => [
            'empresa_id' => $this->empresa->id, 'cliente_id' => $this->cliente->id,
            'valor' => $valor, 'meio' => 'dinheiro', 'fatura_id' => $fatura->id,
        ]];
    }

    /** @return array<string, mixed> */
    protected function abatimento(Fatura $adiantamento, Fatura $final, string $valor): array
    {
        return ['acao' => 'abater', 'args' => [
            'empresa_id' => $this->empresa->id, 'fa_id' => $adiantamento->id, 'ft_id' => $final->id,
            'valor' => $valor, 'chave' => 'carga-mista-abat-'.uniqid(),
        ]];
    }

    /** @return array<string, mixed> uma nota de crédito (rascunho criado aqui, emitido no worker) */
    protected function notaDeCredito(Fatura $fatura, string $preco): array
    {
        $rascunho = app(NotaCreditoDebitoService::class)->criarRascunho(
            $this->empresa->id, $this->cliente->id, 'credito', 'Correção '.uniqid(), $this->linha($preco, 'Correção'), $fatura->id,
        );

        return ['acao' => 'emitir_nota', 'args' => ['nota_id' => $rascunho->id]];
    }

    /** @param  array<int, array<string, mixed>>  $lote */
    protected function correrMisto(array $lote): array
    {
        mt_srand(20261122);
        shuffle($lote);

        $resultados = $this->correrEmParalelo('misto', $lote, $this->ambienteFiscal());

        foreach ($resultados as $r) {
            if ($r['ok']) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^(DomainException|InvalidArgumentException|Modules\\\\Faturacao\\\\Exceptions\\\\[A-Za-z]+Exception): /',
                $r['erro'],
                'Falha técnica em vez de recusa de negócio: '.$r['erro'],
            );
        }

        $recusas = count(array_filter($resultados, fn ($r) => ! $r['ok']));
        $this->assertGreaterThan(0, $recusas, 'Sem recusas não houve disputa pelo saldo.');
        $this->assertGreaterThan(0, count($resultados) - $recusas);

        return $resultados;
    }

    protected function recebido(Fatura $fatura): int
    {
        return $this->centimos(DB::table('recibos')->where('fatura_id', $fatura->id)->where('estado', 'emitido')->sum('valor'));
    }

    protected function creditado(Fatura $fatura): int
    {
        return $this->centimos(DB::table('notas_credito_debito')->where('fatura_id', $fatura->id)->where('tipo', 'credito')->where('estado', 'emitida')->sum('valor_total'));
    }

    protected function abatidoNaFinal(Fatura $final): int
    {
        return (int) DB::table('adiantamento_abatimentos')->where('fatura_final_id', $final->id)->sum('valor_centimos');
    }

    protected function abatidoDoAdiantamento(Fatura $adiantamento): int
    {
        return (int) DB::table('adiantamento_abatimentos')->where('fatura_adiantamento_id', $adiantamento->id)->sum('valor_centimos');
    }

    protected function linhasDeNotas(): int
    {
        return (int) DB::table('notas_credito_debito_linhas')
            ->whereIn('nota_credito_debito_id', DB::table('notas_credito_debito')->where('empresa_id', $this->empresa->id)->where('estado', 'emitida')->pluck('id')->all())
            ->count();
    }

    protected function assertNumeracoesContinuas(): void
    {
        foreach (['recibos', 'notas_credito_debito'] as $tabela) {
            $numeros = DB::table($tabela)->where('empresa_id', $this->empresa->id)->whereNotNull('numero_sequencial')
                ->orderBy('serie_id')->orderBy('numero_sequencial')->get(['serie_id', 'numero_sequencial'])->groupBy('serie_id');

            foreach ($numeros as $serieId => $linhas) {
                $sequencia = $linhas->pluck('numero_sequencial')->map(fn ($n) => (int) $n)->all();
                $this->assertSame(range(1, count($sequencia)), $sequencia, "Lacuna na numeração de {$tabela} (série {$serieId}).");
            }
        }
    }

    public function test_recibos_notas_de_credito_e_abatimentos_sobre_a_mesma_fatura_respeitam_os_limites(): void
    {
        $final = $this->faturaFinal('10000.00'); // 11400.00
        $adiantamento = $this->adiantamentoRecebido(); // 5700.00 por abater

        $lote = [];
        for ($i = 0; $i < 8; $i++) {
            $lote[] = $this->reciboParaFatura($final, '1500.00');
        }
        for ($i = 0; $i < 6; $i++) {
            $lote[] = $this->notaDeCredito($final, '1000.00');
        }
        for ($i = 0; $i < 6; $i++) {
            $lote[] = $this->abatimento($adiantamento, $final, '1000.00');
        }

        $this->correrMisto($lote);

        $total = $this->centimos(Fatura::withoutGlobalScopes()->findOrFail($final->id)->valor_total);
        $recebido = $this->recebido($final);
        $abatido = $this->abatidoNaFinal($final);

        $this->assertSame(1140000, $total);
        $this->assertLessThanOrEqual($total, $recebido + $abatido, sprintf('Cobrado a mais: recebido %d + abatido %d > total %d.', $recebido, $abatido, $total));
        $this->assertLessThanOrEqual($total + $this->linhasDeNotas(), $this->creditado($final), 'As notas de crédito excedem a fatura.');
        $this->assertLessThanOrEqual(570000, $abatido, 'Nunca se abate mais do que o adiantamento recebeu (5700.00).');
        $this->assertNumeracoesContinuas();
    }

    public function test_recibos_e_abatimentos_sem_notas_de_credito_nunca_deixam_o_saldo_negativo(): void
    {
        $final = $this->faturaFinal('10000.00'); // 11400.00
        $adiantamento = $this->adiantamentoRecebido();

        $lote = [];
        for ($i = 0; $i < 12; $i++) {
            $lote[] = $this->reciboParaFatura($final, '1500.00');
        }
        for ($i = 0; $i < 8; $i++) {
            $lote[] = $this->abatimento($adiantamento, $final, '1000.00');
        }

        $this->correrMisto($lote);

        $total = $this->centimos(Fatura::withoutGlobalScopes()->findOrFail($final->id)->valor_total);
        $saldo = $total - $this->recebido($final) - $this->abatidoNaFinal($final);

        $this->assertGreaterThanOrEqual(0, $saldo, "Saldo negativo sem notas de crédito: {$saldo} cêntimos.");
        $this->assertLessThanOrEqual(570000, $this->abatidoNaFinal($final));
        $this->assertNumeracoesContinuas();
    }

    public function test_abatimentos_e_notas_de_credito_sobre_o_mesmo_adiantamento_nao_se_sobrepoem(): void
    {
        $final = $this->faturaFinal('30000.00'); // 34200.00: capacidade de sobra para os abatimentos
        $adiantamento = $this->adiantamentoRecebido(); // 5700.00

        $lote = [];
        for ($i = 0; $i < 6; $i++) {
            $lote[] = $this->abatimento($adiantamento, $final, '1000.00');
        }
        for ($i = 0; $i < 6; $i++) {
            $lote[] = $this->notaDeCredito($adiantamento, '1000.00'); // 1140.00 cada
        }

        $this->correrMisto($lote);

        $total = $this->centimos(Fatura::withoutGlobalScopes()->findOrFail($adiantamento->id)->valor_total);
        $abatido = $this->abatidoDoAdiantamento($adiantamento);
        $creditado = $this->creditado($adiantamento);

        $this->assertSame(570000, $total);
        $this->assertLessThanOrEqual(
            $total + $this->linhasDeNotas(),
            $abatido + $creditado,
            sprintf('A mesma parte do adiantamento foi abatida e creditada: abatido %d + creditado %d > %d.', $abatido, $creditado, $total),
        );
        $this->assertNumeracoesContinuas();
    }
}
