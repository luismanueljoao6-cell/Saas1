<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\GuardarPerfilRequest;
use Modules\Atelier\Models\Perfil;
use Modules\Atelier\Services\SlugEmpresaService;

class PerfilController extends Controller
{
    public function __construct(protected SlugEmpresaService $slugService) {}

    public function editar(): View
    {
        $empresa = request()->user()->empresa;

        return view('atelier::perfil.editar', [
            'empresa' => $empresa,
            'perfil' => Perfil::firstOrNew(['empresa_id' => $empresa->id]),
        ]);
    }

    public function atualizar(GuardarPerfilRequest $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;

        // Empresas registadas depois da migration do Atelier ainda não têm
        // slug (ver SlugEmpresaService) — gera-se aqui, na primeira gravação.
        $this->slugService->garantir($empresa);

        Perfil::updateOrCreate(
            ['empresa_id' => $empresa->id],
            [
                'historia' => $request->validated('historia'),
                'especialidades' => $request->validated('especialidades'),
                'equipa' => $request->validated('equipa'),
                'redes_sociais' => $request->validated('redes_sociais'),
                'publicado' => $request->boolean('publicado'),
            ],
        );

        return back()->with('sucesso', 'Perfil público atualizado.');
    }
}
