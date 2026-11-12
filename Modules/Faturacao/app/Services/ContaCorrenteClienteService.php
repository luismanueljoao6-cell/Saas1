<?php

namespace Modules\Faturacao\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Support\Dinheiro;

class ContaCorrenteClienteService
{
    /**
     * Extrato cronológico do cliente. Todos os valores são tratados em cêntimos.
     * O recibo de adiantamento é reconhecido na data em que foi recebido; o
     * abatimento posterior é apenas uma transferência de crédito para a FT,
     * nunca um segundo recebimento de dinheiro.
     *
     * @return array{movimentos: Collection, saldoInicial: int, totalDebito: int, totalCredito: int, saldoFinal: int, inicio: string, fim: string}
     */
    public function extrato(Cliente $cliente, string $inicio, string $fim): array
    {
        $empresaId = app(TenantManager::class)->id();

        if (! $empresaId || (int) $cliente->empresa_id !== $empresaId) {
            throw new \DomainException('O cliente não pertence à empresa ativa.');
        }

        $dataInicio = CarbonImmutable::parse($inicio)->startOfDay();
        $dataFim = CarbonImmutable::parse($fim)->endOfDay();

        if ($dataFim->lt($dataInicio)) {
            throw new \InvalidArgumentException('A data final não pode ser anterior à data inicial.');
        }

        $anteriores = $this->movimentosAte($cliente, $dataInicio, true);
        $doPeriodo = $this->movimentosDoPeriodo($cliente, $dataInicio, $dataFim);

        $saldoInicial = $anteriores->sum(fn (array $m) => $m['debito'] - $m['credito']);
        $totalDebito = $doPeriodo->sum('debito');
        $totalCredito = $doPeriodo->sum('credito');

        $saldo = $saldoInicial;
        $movimentos = $doPeriodo
            ->sortBy([['data', 'asc'], ['ordem', 'asc'], ['id', 'asc']])
            ->values()
            ->map(function (array $movimento) use (&$saldo): array {
                $saldo += $movimento['debito'] - $movimento['credito'];
                $movimento['saldo'] = $saldo;

                return $movimento;
            });

        return [
            'movimentos' => $movimentos,
            'saldoInicial' => $saldoInicial,
            'totalDebito' => $totalDebito,
            'totalCredito' => $totalCredito,
            'saldoFinal' => $saldoInicial + $totalDebito - $totalCredito,
            'inicio' => $dataInicio->toDateString(),
            'fim' => $dataFim->toDateString(),
        ];
    }

    private function movimentosAte(Cliente $cliente, CarbonImmutable $inicio, bool $estritamenteAntes): Collection
    {
        return $this->consultarMovimentos($cliente, null, $inicio->subSecond(), true);
    }

    private function movimentosDoPeriodo(Cliente $cliente, CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        return $this->consultarMovimentos($cliente, $inicio, $fim, false);
    }

    private function consultarMovimentos(
        Cliente $cliente,
        ?CarbonImmutable $inicio,
        ?CarbonImmutable $fim,
        bool $antes,
    ): Collection {
        $empresaId = (int) $cliente->empresa_id;
        $clienteId = (int) $cliente->id;
        $movimentos = collect();

        $faturas = DB::table('faturas')
            ->where('empresa_id', $empresaId)
            ->where('cliente_id', $clienteId)
            ->where('estado', 'emitida')
            ->whereIn('tipo_documento', ['FT', 'FA']);
        $this->aplicarDatas($faturas, 'data_emissao', $inicio, $fim, $antes);
        foreach ($faturas->get(['id', 'tipo_documento', 'numero_documento', 'data_emissao', 'valor_total']) as $row) {
            $movimentos->push($this->movimento(
                (int) $row->id,
                'fatura',
                $row->numero_documento ?: $row->tipo_documento,
                $row->data_emissao,
                Dinheiro::centimos($row->valor_total),
                0,
                10,
            ));
        }

        $notas = DB::table('notas_credito_debito')
            ->where('empresa_id', $empresaId)
            ->where('cliente_id', $clienteId)
            ->where('estado', 'emitida');
        $this->aplicarDatas($notas, 'data_emissao', $inicio, $fim, $antes);
        foreach ($notas->get(['id', 'tipo', 'numero_documento', 'data_emissao', 'valor_total']) as $row) {
            $credito = $row->tipo === 'credito';
            $movimentos->push($this->movimento(
                (int) $row->id,
                $credito ? 'nota_credito' : 'nota_debito',
                $row->numero_documento ?: ($credito ? 'Nota de crédito' : 'Nota de débito'),
                $row->data_emissao,
                $credito ? 0 : Dinheiro::centimos($row->valor_total),
                $credito ? Dinheiro::centimos($row->valor_total) : 0,
                20,
            ));
        }

        $recibos = DB::table('recibos')
            ->where('empresa_id', $empresaId)
            ->where('cliente_id', $clienteId)
            ->where('estado', 'emitido');
        $this->aplicarDatas($recibos, 'data_emissao', $inicio, $fim, $antes);
        foreach ($recibos->get(['id', 'numero_documento', 'data_emissao', 'valor']) as $row) {
            $movimentos->push($this->movimento(
                (int) $row->id,
                'recibo',
                $row->numero_documento ?: 'Recibo',
                $row->data_emissao,
                0,
                Dinheiro::centimos($row->valor),
                30,
            ));
        }

        // O abatimento liquida parte da FT usando crédito já recebido no FA.
        // É um crédito de regularização na conta corrente, não novo dinheiro.
        $abatimentos = DB::table('adiantamento_abatimentos')
            ->join('faturas as finais', 'finais.id', '=', 'adiantamento_abatimentos.fatura_final_id')
            ->where('adiantamento_abatimentos.empresa_id', $empresaId)
            ->where('finais.empresa_id', $empresaId)
            ->where('finais.cliente_id', $clienteId);
        $this->aplicarDatas($abatimentos, 'adiantamento_abatimentos.created_at', $inicio, $fim, $antes);
        foreach ($abatimentos->get([
            'adiantamento_abatimentos.id',
            'adiantamento_abatimentos.created_at',
            'adiantamento_abatimentos.valor_centimos',
        ]) as $row) {
            $movimentos->push($this->movimento(
                (int) $row->id,
                'abatimento_adiantamento',
                'Abatimento de adiantamento',
                substr((string) $row->created_at, 0, 10),
                0,
                (int) $row->valor_centimos,
                40,
            ));
        }

        return $movimentos;
    }

    private function aplicarDatas($query, string $coluna, ?CarbonImmutable $inicio, ?CarbonImmutable $fim, bool $antes): void
    {
        if ($antes && $fim) {
            $query->where($coluna, '<=', $fim->toDateTimeString());

            return;
        }

        if ($inicio) {
            $query->where($coluna, '>=', $inicio->toDateTimeString());
        }

        if ($fim) {
            $query->where($coluna, '<=', $fim->toDateTimeString());
        }
    }

    private function movimento(int $id, string $tipo, string $documento, ?string $data, int $debito, int $credito, int $ordem): array
    {
        return [
            'id' => $id,
            'tipo' => $tipo,
            'documento' => $documento,
            'data' => $data ? substr((string) $data, 0, 10) : '',
            'debito' => $debito,
            'credito' => $credito,
            'ordem' => $ordem,
        ];
    }
}
