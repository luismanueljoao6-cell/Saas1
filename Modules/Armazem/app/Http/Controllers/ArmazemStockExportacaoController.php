<?php

namespace Modules\\Armazem\\Http\\Controllers;

use Illuminate\\Database\\Query\\JoinClause;
use Illuminate\\Http\\Request;
use Illuminate\\Routing\\Controller;
use Illuminate\\Support\\Facades\\DB;
use Symfony\\Component\\HttpFoundation\\StreamedResponse;

class ArmazemStockExportacaoController extends Controller
{
    public function saldos(Request $request): StreamedResponse
    {
        $filtros = $request->validate([
            'armazem_id' => ['nullable', 'integer'],
            'pesquisa' => ['nullable', 'string', 'max:100'],
            'abaixo_minimo' => ['nullable', 'boolean'],
        ]);
        $empresaId = (int) $request->user()->empresa_id;

        $query = DB::table('armazem_stock_saldos as saldos')
            ->join('armazens', 'armazens.id', '=', 'saldos.armazem_id')
            ->join('produtos', 'produtos.id', '=', 'saldos.produto_id')
            ->leftJoin('armazem_stock_minimos as minimos', function (JoinClause $join): void {
                $join->on('minimos.empresa_id', '=', 'saldos.empresa_id')
                    ->on('minimos.armazem_id', '=', 'saldos.armazem_id')
                    ->on('minimos.produto_id', '=', 'saldos.produto_id');
            })
            ->where('saldos.empresa_id', $empresaId)
            ->where('armazens.empresa_id', $empresaId)
            ->where('produtos.empresa_id', $empresaId)
            ->select([
                'produtos.codigo',
                'produtos.nome as produto',
                'armazens.nome as armazem',
                'saldos.quantidade',
                'minimos.quantidade_minima',
            ])
            ->when($filtros['armazem_id'] ?? null, fn ($q, $id) => $q->where('saldos.armazem_id', $id))
            ->when($filtros['pesquisa'] ?? null, fn ($q, $termo) => $q->where('produtos.nome', 'like', '%'.$termo.'%'))
            ->when(($filtros['abaixo_minimo'] ?? false) === true || ($filtros['abaixo_minimo'] ?? null) === '1', function ($q): void {
                $q->whereNotNull('minimos.id')
                    ->whereColumn('saldos.quantidade', '<=', 'minimos.quantidade_minima');
            })
            ->orderBy('armazens.nome')
            ->orderBy('produtos.nome');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Código', 'Produto', 'Armazém', 'Saldo', 'Stock mínimo', 'Estado'], ';');

            foreach ($query->cursor() as $linha) {
                $abaixo = $linha->quantidade_minima !== null
                    && (float) $linha->quantidade <= (float) $linha->quantidade_minima;
                fputcsv($output, [
                    $this->textoSeguro($linha->codigo),
                    $this->textoSeguro($linha->produto),
                    $this->textoSeguro($linha->armazem),
                    number_format((float) $linha->quantidade, 3, '.', ''),
                    $linha->quantidade_minima === null ? '' : number_format((float) $linha->quantidade_minima, 3, '.', ''),
                    $abaixo ? 'Abaixo do mínimo' : 'Normal',
                ], ';');
            }

            fclose($output);
        }, 'relatorio-saldos-stock-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function textoSeguro(?string $valor): string
    {
        $valor = trim((string) $valor);

        return preg_match('/^[=+@\\-\\t\\r]/u', $valor) === 1 ? "'".$valor : $valor;
    }
}
