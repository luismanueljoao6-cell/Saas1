<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;

class PainelFaturacaoController extends Controller
{
    public function __invoke(): View
    {
        $inicioMes = now()->startOfMonth();
        $fimMes = now()->endOfMonth();

        $faturasEmitidas = Fatura::query()
            ->where('estado', 'emitida')
            ->whereBetween('data_emissao', [$inicioMes->toDateString(), $fimMes->toDateString()]);

        $recibosEmitidos = Recibo::query()
            ->where('estado', 'emitido')
            ->whereBetween('data_emissao', [$inicioMes->toDateString(), $fimMes->toDateString()]);

        return view('faturacao::painel.index', [
            'resumo' => [
                'documentos_emitidos_mes' => (clone $faturasEmitidas)->count(),
                'total_faturado_mes' => (clone $faturasEmitidas)->sum('valor_total'),
                'recebido_mes' => (clone $recibosEmitidos)->sum('valor'),
                'rascunhos' => Fatura::query()->where('estado', 'rascunho')->count(),
            ],
            'faturasRecentes' => Fatura::query()
                ->with('cliente')
                ->latest('id')
                ->limit(8)
                ->get(),
            'recibosRecentes' => Recibo::query()
                ->with('cliente', 'fatura')
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }
}
