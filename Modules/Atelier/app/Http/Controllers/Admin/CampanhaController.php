<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 *
 * Marketing é só para Administradores — em TODAS as ações, não só ao criar.
 */
class CampanhaController extends Controller
{
    public function index(Request $request): View
    {
        $this->autorizar($request);

        return view('atelier::campanhas.index', [
            'campanhas' => Campanha::latest('id')->paginate(20),
        ]);
    }

    public function criar(Request $request): View
    {
        $this->autorizar($request);

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

    public function mostrar(Request $request, int $campanha): View
    {
        $this->autorizar($request);

        $campanha = Campanha::findOrFail($campanha);

        return view('atelier::campanhas.mostrar', [
            'campanha' => $campanha->load('envios.cliente'),
        ]);
    }

    public function enviarAgora(Request $request, int $campanha): RedirectResponse
    {
        $this->autorizar($request);

        $campanha = Campanha::findOrFail($campanha);

        abort_if($campanha->estado === 'cancelada', 422, 'Esta campanha foi cancelada.');

        // Campanha já enviada só pode ser relançada para reenviar os envios
        // que falharam (o job ignora os já enviados e respeita o consentimento atual).
        abort_if(
            $campanha->estado === 'enviada' && ! $campanha->envios()->where('estado', 'falhou')->exists(),
            422,
            'Esta campanha já foi enviada.'
        );

        // Um clique duplo despacha dois jobs, mas o job tem um lock por
        // campanha e é idempotente: o segundo não reenvia nada.
        DispararCampanhaJob::dispatch($campanha->id);

        return back()->with('sucesso', 'Envio iniciado — os estados de entrega vão aparecer aqui à medida que forem processados.');
    }

    protected function autorizar(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Administrador'), 403);
    }
}
