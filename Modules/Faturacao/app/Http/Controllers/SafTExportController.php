<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Modules\Faturacao\Jobs\GerarSafTJob;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SafTExportController extends Controller
{
    protected function empresaId(Request $request): int
    {
        $id = $request->user()?->empresa_id;
        abort_if($id === null, 403, 'Utilizador sem empresa associada.');

        return (int) $id;
    }

    public function criar(Request $request): View
    {
        $empresaId = $this->empresaId($request);
        $disco = Storage::disk('local');

        $ficheiros = collect($disco->files("faturacao/saft/{$empresaId}"))
            ->map(fn (string $c) => [
                'nome' => basename($c),
                'tamanho' => $disco->size($c),
                'modificado_em' => Carbon::createFromTimestamp($disco->lastModified($c)),
            ])
            ->sortByDesc('modificado_em')
            ->values();

        return view('faturacao::saft.exportar', ['ficheiros' => $ficheiros]);
    }

    public function despachar(Request $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403, 'Utilizador sem empresa associada.');

        $dados = $request->validate([
            'data_inicio' => ['required', 'date_format:Y-m-d'],
            'data_fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_inicio', 'before_or_equal:today'],
        ]);

        $dias = Carbon::parse($dados['data_inicio'])->diffInDays(Carbon::parse($dados['data_fim']));

        if (abs($dias) > 366) {
            return back()->withErrors(['data_fim' => 'O período máximo é de 366 dias.'])->withInput();
        }

        GerarSafTJob::dispatch($empresa, $dados['data_inicio'], $dados['data_fim']);

        return back()->with('sucesso', 'Exportação iniciada. Atualiza esta página dentro de instantes — o ficheiro aparece na lista abaixo.');
    }

    public function descarregar(Request $request, string $ficheiro): StreamedResponse
    {
        abort_unless(preg_match('/^SAFT_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.xml$/', $ficheiro) === 1, 404);

        $caminho = "faturacao/saft/{$this->empresaId($request)}/{$ficheiro}";
        abort_unless(Storage::disk('local')->exists($caminho), 404);

        return Storage::disk('local')->download($caminho, $ficheiro, ['Content-Type' => 'application/xml']);
    }
}
