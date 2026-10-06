<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\GuardarCampanhaRequest;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Campanha;

/**
 * IMPORTANTE: {campanha} chega como int, nunca como Campanha tipado (route
 * model binding implícito). O Laravel resolve o binding ANTES do
 * middleware 'tenant' correr — nessa altura ainda não há empresa
 * definida, e a TenantScope (fail-closed) faria o binding devolver sempre
 * 404. O findOrFail() dentro de cada método já corre com o tenant certo.
 * Mesmo padrão de Modules\Faturacao\Http\Controllers\FaturaController.
 */
class CampanhaController extends Controller
{
    public function index(): View
    {
        return view('atelier::campanhas.index', [
            'campanhas' => Campanha::latest('id')->paginate(20),
        ]);
    }

    public function criar(): View
    {
        return view('atelier::campanhas.criar');
    }

    public function guardar(GuardarCampanhaRequest $request): RedirectResponse
    {
        $campanha = Campanha::create([
            ...$request->validated(),
            'criada_por_id' => $request->user()->id,
            'estado' => $request->validated('agendada_para') ? 'agendada' : 'rascunho',
        ]);

        return redirect()->route('atelier.campanhas.mostrar', $campanha)->with('sucesso', 'Campanha criada.');
    }

    public function mostrar(int $campanha): View
    {
        $campanha = Campanha::findOrFail($campanha);

        return view('atelier::campanhas.mostrar', [
            'campanha' => $campanha->load('envios.cliente'),
        ]);
    }

    public function enviarAgora(int $campanha): RedirectResponse
    {
        $campanha = Campanha::findOrFail($campanha);

        abort_if($campanha->estado === 'enviada', 422, 'Esta campanha já foi enviada.');

        DispararCampanhaJob::dispatch($campanha->id);

        return back()->with('sucesso', 'Envio iniciado — os estados de entrega vão aparecer aqui à medida que forem processados.');
    }
}
