<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Atelier\Http\Requests\AgendarProvaRequest;
use Modules\Atelier\Http\Requests\AtualizarEstadoProvaRequest;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\Prova;

/**
 * IMPORTANTE: {pedido}/{prova} chegam como int, nunca tipados (route model
 * binding implícito). O Laravel resolve o binding ANTES do middleware
 * 'tenant' correr — nessa altura ainda não há empresa definida, e a
 * TenantScope (fail-closed) faria o binding devolver sempre 404.
 */
class ProvaController extends Controller
{
    public function guardar(AgendarProvaRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);

        $pedido->provas()->create([
            'empresa_id' => $pedido->empresa_id,
            'tipo' => $request->validated('tipo'),
            'data_hora_agendada' => $request->validated('data_hora_agendada'),
            'notas' => $request->validated('notas'),
        ]);

        return back()->with('sucesso', 'Prova agendada.');
    }

    /**
     * Cobre tanto marcar confirmada/concluída/falta como aceitar a data que
     * o cliente propôs no Portal (requisito E.4) — nesse caso, o pedido
     * inclui nova_data_hora_agendada e reiniciamos o lembrete (uma prova
     * reagendada para uma nova hora precisa de um novo lembrete 24h antes).
     */
    public function atualizarEstado(AtualizarEstadoProvaRequest $request, int $prova): RedirectResponse
    {
        $prova = Prova::findOrFail($prova);

        $dados = ['estado' => $request->validated('estado')];

        if ($request->validated('notas') !== null) {
            $dados['notas'] = $request->validated('notas');
        }

        if ($novaData = $request->validated('nova_data_hora_agendada')) {
            $dados['data_hora_agendada'] = $novaData;
            $dados['data_hora_proposta_cliente'] = null;
            $dados['lembrete_enviado_em'] = null;
        }

        $prova->update($dados);

        return back()->with('sucesso', 'Prova atualizada.');
    }
}
