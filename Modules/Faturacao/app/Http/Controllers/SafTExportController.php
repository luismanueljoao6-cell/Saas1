<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Faturacao\Jobs\GerarSafTJob;

class SafTExportController extends Controller
{
    public function criar(): View
    {
        return view('faturacao::saft.exportar');
    }

    public function despachar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
        ]);

        GerarSafTJob::dispatch($request->user()->empresa, $dados['data_inicio'], $dados['data_fim']);

        return back()->with('sucesso', 'Exportação SAF-T (AO) iniciada — corre em segundo plano; verifica o storage/logs quando terminar (ver README).');
    }
}
